# Oryk Provisioner

Template-driven endpoint provisioning for FreePBX 16 and 17.

A phone asks the PBX for a configuration file by its MAC address. The module
works out which profile that MAC belongs to, finds the file of that profile the
phone asked for, fills in its placeholders from FreePBX, and serves it.

Vendor-specific syntax stays in templates you write; the module holds the data
and the rendering.

> ### Status
>
> This is Stage 1 and it is deliberately smaller than the design it is working
> towards. **The provisioning endpoint is unauthenticated and keyed on MAC
> address**: anyone who can reach the URL and knows a MAC gets that phone's
> configuration, SIP secret included. Read [Security](#security) before putting
> it anywhere public. Provisioning tokens, a GraphQL API, per-client parameter
> overrides and a bundled vendor template library are [not built
> yet](#not-built-yet).

---

## Getting Started

`git clone git@github.com:alainmenag/freepbx-oryk-provisioner.git oryk_provisioner`

---

## What it does today

- Associates a MAC address with a FreePBX device and a provisioning profile.
- Serves every file a phone asks its profile for — the main config, phone,
  web, directory — each one a filename and a template you write.
- Fills `{{placeholder}}` names from the client, its FreePBX device, that
  device's extension, and the device's own SIP settings.
- Answers phones on an unauthenticated endpoint at `/provisioner/`, without an
  admin session.
- Gives you an admin page per client, profile and resource, and a per-row
  Render link so you can see exactly what a given phone gets.
- Logs metadata only — MAC, file, profile. Never the rendered body.

---

## The model

```
Client                    Profile              Resources
------                    -------              ---------
0004f282e824          ->  Polycom VVX 500  ->  .cfg
device 1001 (ext 1001)                         phone.cfg
                                               {{device.mac}}-directory.xml

00908f3bbcba          ->  Polycom VVX 500
device 1002 (ext 1002)
```

**Client** — a MAC address, optionally a FreePBX device, optionally a profile.
The MAC is the only required field; it is unique, and stored as 12 lowercase
hexadecimal characters with any separators stripped. The FreePBX `devices`
table stays the source of truth for the device, its extension and its
description, so a client carries none of its own.

**Profile** — a name, and the files it serves. Nothing else: a profile holds no
configuration text of its own.

**Resource** — one file. A filename and a template. The main configuration file
is a resource like every other file, named `.cfg`.

A client with no device still provisions — the device-derived placeholders are
simply empty, which is what a profile of static configuration wants. A client
with no profile is served nothing.

---

## Quick start

**1. Make a profile.** *Oryk → Provisioner → Profiles → Add Profile*. Give it a
name (`Polycom VVX 500`) and save.

**2. Add the main config.** On the profile's **Resources** tab, *Add Resource*.
Filename `.cfg`, and a template:

```
reg.1.address="{{extension.number}}"
reg.1.auth.userId="{{device.username}}"
reg.1.auth.password="{{device.secret}}"
reg.1.label="{{extension.name}}"
reg.1.server.1.address="{{server.host}}"
reg.1.server.1.port="{{server.port}}"
reg.1.server.1.transport="{{sip.transport}}"
```

Add more resources for the other files the phone fetches — `phone.cfg`,
`directory.xml`, and so on.

**3. Add a client.** *Clients → Add Client*. Enter the MAC, pick the FreePBX
device it stands for, pick the profile, save.

**4. Check what it will get.** Open the client and look at its **Resources**
tab: every file it asks for, under the filename it will ask for, with a
**Render** link that fetches it exactly as the phone will.

**5. Point the phone at it.**

```
http://<pbx>/provisioner/
```

Most phones append their own MAC and filename to that. Nothing else to
configure.

---

## The provisioning endpoint

`engine/provisioner.php`, reached through a symlink in the web root, is the
only route a phone can use. It bootstraps FreePBX itself: FreePBX 16/17 sends
every session-less `config.php` request to the login page before a module's
`doConfigPageInit()` ever runs, so a public route cannot go through `config.php`
at all — `requires_auth="false"` governs menu visibility, not anonymous access.

| Request | Serves |
| --- | --- |
| `/provisioner/0004f282e824.cfg` | that profile's `.cfg` resource |
| `/provisioner/0004f282e824` | the same |
| `/provisioner/?mac=0004f282e824` | the same, for a caller with no filename to give |
| `/provisioner/0004f282e824-phone.cfg` | its `phone.cfg` resource |
| `/provisioner/0004f282e824-directory.xml` | its `directory.xml` resource |

**Who is asking** is read from the path, then the query string, then an
AudioCodes `User-Agent`. **A MAC in the path always wins over `?mac=`** — which
matters for a filename that carries somebody else's MAC, such as the
`000000000000-directory.xml` a Polycom really does request: it resolves to
`000000000000` and cannot be redirected with `?mac=`.

**What they asked for** is the last segment of the path, verbatim. Which
resource of which profile that names is worked out by the module.

GET and HEAD are served. Anything else — including the boot and app logs a
phone PUTs — is a 404 and a log line.

A MAC that is not associated, a client with no profile, and a filename the
profile does not serve are all 404s.

---

## How a filename is matched

A resource's name is itself a template, and it matches a request two ways:

| Written as | Matches | Because |
| --- | --- | --- |
| `{{device.mac}}-phone.cfg` | `0004f282e824-phone.cfg` | the name is rendered with this client's values and compared to the request |
| `phone.cfg` | `0004f282e824-phone.cfg` | the name is compared to the request with this client's MAC taken off the front |

Both are the same string in the same column — the second is only what the first
becomes when it has no placeholders in it. A name written out in full wins over
one that matches only the tail. Matching ignores case throughout, and the MAC
is stripped in whichever separator style the phone used (`0004f282e824`,
`00:04:f2:82:e8:24`, `0004.f282.e824`).

**Name the main config `.cfg`.** Written that way it is matched with the MAC
taken off the front, so it answers whichever separator style the phone asks in.
`{{device.mac}}.cfg` works too, but it renders without separators and so only
answers a phone that asks that way. A profile with neither serves nothing for
`<mac>.cfg`.

Names are unique per profile, not globally — two profiles both serving a
`{{device.mac}}-phone.cfg` is the normal case.

**Content type** is taken from the extension: `.xml` is served as `text/xml`,
`.json` as `application/json`, everything else as `text/plain`.

---

## Template placeholders

`{{name}}`, filled in when a client asks for the file. A name nothing answers to
renders as nothing — a phone copes with an empty value, not with a literal
`{{ }}` where a value belongs. Both editors list the names under the template
box; click one to copy it.

| Placeholder | Value |
| --- | --- |
| `{{device.mac}}` | `0004f282e824` |
| `{{device.mac_upper}}` | `0004F282E824` |
| `{{device.mac_colon}}` | `00:04:f2:82:e8:24` |
| `{{device.mac_colon_upper}}` | `00:04:F2:82:E8:24` |
| `{{device.id}}` | the FreePBX device id |
| `{{device.description}}` | its description |
| `{{device.tech}}` | `pjsip` or `sip` |
| `{{device.username}}` | SIP username |
| `{{device.secret}}` | SIP secret |
| `{{extension.number}}` | the extension the device is attached to |
| `{{extension.name}}` | display name |
| `{{extension.voicemail}}` | voicemail setting |
| `{{profile.id}}`, `{{profile.name}}` | the profile serving the file |
| `{{server.host}}` | the host the request arrived on, port stripped |
| `{{server.port}}` | `5060` |

Plus **everything else the device is configured with in FreePBX**, under a `sip.`
prefix — one placeholder per row the device has in the `sip` table, so
vendor-specific values stay in your templates rather than in the module:

```
{{sip.transport}}   {{sip.callerid}}   {{sip.dtmfmode}}   ...
```

Non-alphanumerics in a keyword fold to `_`, so `dtmf-mode` is `{{sip.dtmf_mode}}`.

There are no filters, sections or escaping yet — but the delimiters and the
dotted names are the ones the full engine will use, so templates written now
keep rendering.

A site whose `sip` or `users` tables are missing a lookup degrades to empty
values rather than a 500.

---

## The admin interface

*Oryk → Provisioner*. A list and three editors, told apart by which key the URL
carries. Every tab is in the address too, so a reload, a bookmark or a link from
elsewhere in the module lands where you were.

| URL | Page | Tabs |
| --- | --- | --- |
| `?display=oryk_provisioner` | the list | Clients, Profiles |
| `&client=<id>` | one client (`&client=` for a new one) | Client, Resources |
| `&profile=<id>` | one profile (`&profile=` for a new one) | Profile, Resources, Clients |
| `&profile=<id>&resource=<id>` | one file (`&resource=` for a new one) | Resource, Clients |

Both list tabs are paginated, searchable and sortable server-side. Save, Delete
and Close are in the FreePBX action bar on every editor.

**The two preview tabs** are one idea from both ends: the client editor's
**Resources** tab is one phone over all the files it gets; the resource
editor's **Clients** tab is one file over all the phones that get it. A
resource's rendered filename depends on both, so the row where the two meet is
the only place such a link can exist. Filenames shown there are produced by the
same code the endpoint uses, so they are what a phone actually asks for. A file
whose name carries another device's MAC gets its filename and no Render button
— the link would answer for the wrong phone.

**Deleting a profile** that clients are still assigned to is refused rather than
cascading; its resources do cascade, since a resource has no existence apart
from the profile that serves it.

---

## Database

Three tables, all created by `install()` with `CREATE TABLE IF NOT EXISTS`.

**`oryk_provisioner_clients`** — `id`, `mac` (unique, 12 lowercase hex),
`device_id`, `profile_id`, `created_at`, `updated_at`.
`device_id` is a `VARCHAR(20)` because FreePBX `devices.id` is a string column,
and it keeps that name deliberately: it holds a FreePBX device id, which is the
one thing on the row that is still a device.

**`oryk_provisioner_profiles`** — `id`, `name` (unique), `created_at`,
`updated_at`.

**`oryk_provisioner_resources`** — `id`, `profile_id`, `name`, `template`
(LONGTEXT), `created_at`, `updated_at`. Unique on `(profile_id, name)`.

`uninstall()` leaves all three in place.

---

## Installing

Install the module as usual (`fwconsole ma install oryk_provisioner`, or upload
it in Module Admin).

`install()` also symlinks the module's `engine/` directory into the web root:

```
/var/www/html/provisioner -> .../admin/modules/oryk_provisioner/engine
```

which is what gives phones a short URL instead of a path under `/admin/` that a
hardened site may not serve anonymously at all. Apache has to be willing to
follow it — `Options FollowSymLinks` on the web root, the FreePBX default.

Nothing about the link fails the install. If the web root is not writable, or
something else already lives at `/provisioner`, you get a message on the console
and the endpoint stays reachable at its real path:

```
http://<pbx>/provisioner/<mac>
```

`engine/.htaccess` rewrites everything under the directory to `provisioner.php`,
so the filename a phone asks for arrives as the request path. `uninstall()`
removes the symlink — and only if it still resolves to this module's engine.

---

## Security

Know what this is before you expose it:

- **The endpoint is unauthenticated and keyed on MAC address.** Anyone who can
  reach the URL and knows — or guesses — a MAC gets that client's rendered
  configuration, including `device.secret` if the template emits it. This is
  inherent to MAC-based provisioning and the reason the token scheme exists in
  the design. Until it lands, restrict who can reach `/provisioner/` at the
  network layer, and prefer HTTPS.
- **Failures are not uniform.** A 404 says which kind of failure it was
  ("… is not associated with anything", "… has no profile assigned"), so a
  caller probing MACs can tell a known one from an unknown one.
- **Secrets are not masked anywhere in the UI.** The Render links serve the real
  rendered file, secret included.
- The provisioning log records metadata only — MAC, file, profile — never the
  rendered body or any parameter value.
- Sortable columns are whitelisted and mapped to SQL names before being written
  into a statement; everything else is bound.

---

## Not built yet

The larger design this is working towards, none of which exists in the code:

- **Provisioning tokens** — the `token=…&filename=…` URL scheme, per-client
  enable/disable, and a uniform 404 for every kind of failure.
- **Per-client parameters** and the resolution order (module settings → schema
  defaults → template defaults → FreePBX/extension → client overrides). A client
  today is a MAC, a device and a profile; nothing overrides anything.
- **A bundled template library** for Yealink, Poly, Grandstream and a generic
  softphone. Every template is one you write, and a profile is set up one file
  at a time from empty — there is no seeding and no copying resources between
  profiles.
- **Static resources.** Everything renders; there is no verbatim type for
  content with literal braces in it, and no firmware serving.
- **A GraphQL API**, a `fwconsole` command, module settings, and a stored
  provisioning log with a page to read it.
- **Template filters, sections and content-type-aware escaping.**
- **Somewhere for a phone's boot and app logs to go** — they are PUT, and PUT is
  a 404.
- Backup and restore hooks are stubs.

---

## Code map

```
Oryk_provisioner.class.php   BMO: install/uninstall, page dispatch, AJAX
                             commands, and the renderer (renderConfig,
                             matchResource, provisioningValues, serveConfig)
engine/provisioner.php       the unauthenticated endpoint: who is asking and
                             what they asked for, and nothing else
engine/.htaccess             rewrites the engine directory to provisioner.php
page.oryk_provisioner.php    one line into showPage()
views/admin.php              the list
views/client.php             the client editor
views/profile.php            the profile editor
views/resource.php           the resource editor
views/partials/editor.php    the CSS and JS all three editors share
views/partials/placeholders.php   the placeholder reference
```

AJAX commands, all authenticated through `ajax.php`: `listClients`,
`listProfiles`, `listResources`, `saveClient`, `saveProfile`, `saveResource`,
`deleteClient`, `deleteProfile`, `deleteResource`.
