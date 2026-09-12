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
> towards. **A client with no token is served to anyone who can reach the URL
> and knows its MAC address** — configuration, SIP secret included. A client
> that has been given a token must present it, and is a 401 until it does, but
> a token is opt-in per client and nothing makes you set one. Read
> [Security](#security) before putting this anywhere public. A GraphQL API,
> per-client parameter overrides and a bundled vendor template library are [not
> built yet](#not-built-yet).

---

## Getting Started

`git clone git@github.com:alainmenag/freepbx-oryk-provisioner.git oryk_provisioner`

---

## What it does today

- Associates a MAC address with a FreePBX device and a provisioning profile.
- Serves every file a phone asks its profile for — the main config, phone,
  web, directory — each one a filename and a **type**: a template you write and
  the module renders, or a file you upload and it hands over exactly as stored
  (firmware, ringtones).
- Takes the boot and app logs a phone PUTs back, when the profile has a
  resource of type **Log** by that name, and keeps them per client under
  `ASTLOGDIR/provisioner/<client id>/` — the id rather than the MAC, so a
  corrected MAC does not strand what a phone has already sent, and deleting a
  client takes its logs with it.
- Fills `{{placeholder}}` names from the client, its FreePBX device, that
  device's extension, and the device's own SIP settings.
- Answers phones at `/provisioner/`, without an admin session — and, for a
  client you have given a token, only against HTTP Basic credentials that
  verify against the stored hash.
- Gives you an admin page per client, profile and resource, and a per-row
  Render link so you can see exactly what a given phone gets.
- Lets you switch a client — or a whole profile — off from its row on the
  list, or from its own page: every request it makes is refused until you
  switch it back on, and nothing about it is lost in the meantime. Switching a
  profile off stops every client assigned to it at once, without touching one
  of them.
- Records every request the endpoint answered — MAC, filename, status, method,
  address, User-Agent — on a Logs tab of its own and on each client's page.
  Metadata only: never the rendered body.

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

A client can also be told **where the phone is**: a **private IP**, the address
the handset answers its own web interface on, and a **public IP**, the address
the site it sits behind is reached at from outside. Both are written down rather
than discovered — nothing in the module connects to a phone, so nothing can fill
them in — and both must be an IPv4 or IPv6 address. Given a private address, the
client's row on the Clients list grows a button that opens the handset's web
interface in a new tab; the public one is kept for reference. Both are searched
by the box above the list, and both render into a configuration as
`{{client.private_ip}}` and `{{client.public_ip}}`.

A client can also be given a **token**: a secret typed as `user:password` —
`username:password` — and hashed with `password_hash()` when you save. The
Token box holds that hash from then on, so leaving it alone leaves the token
alone, typing a new `user:password` over it replaces it, and emptying it takes
it away. **A colon is what marks a value as a token still to be hashed**; a
value without one is stored as it was typed and verifies against nothing.

While a client has a token, the endpoint refuses it everything until it
presents one: the request needs HTTP Basic credentials, and `user:password` is
checked with `password_verify()` against the stored hash. A wrong token, or
none, is a `401` with a `WWW-Authenticate` header — asked for as soon as a
filename has matched, and before the resource's type is looked at, so a 401
cannot tell an unauthenticated caller which files exist.

**Profile** — a name, and the files it serves. Nothing else: a profile holds no
configuration text of its own.

**Resource** — one file. A filename, and a type that says what the filename
gets: **Template** (rendered for the client that asked), **File** (uploaded
here, served byte for byte) or **Log** (received from the phone rather than
served to it, and read back at the same URL). The main configuration file is a
resource like every other file, named `.cfg`.

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
| `/provisioner/3111-44500-001.sip.ld` | a File resource of any enabled profile, by name alone — a phone fetching firmware sends no MAC |
| `PUT /provisioner/0004f282e824-boot.log` | stores the body, when that profile has a Log resource answering to the name |

**Who is asking** is read from the path, then the query string, then an
AudioCodes `User-Agent`. **A MAC in the path always wins over `?mac=`** — which
matters for a filename that carries somebody else's MAC, such as the
`000000000000-directory.xml` a Polycom really does request: it resolves to
`000000000000` and cannot be redirected with `?mac=`.

**What they asked for** is the last segment of the path, verbatim. Which
resource of which profile that names is worked out by the module.

GET and HEAD fetch; PUT sends. A PUT needs a MAC — what a phone sends is
written to disk, so it has to be a phone this module knows — where a fetch does
not, since firmware is asked for by name alone. Anything else is a 404 and a
log line.

A MAC that is not associated, a client with no profile, a client or profile
that has been switched off, and a filename the profile does not serve are all
404s. A client that has a token and did not present it is a 401.

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
`.json` as `application/json`, `.cfg`/`.conf`/`.ini`/`.txt`/`.log` as
`text/plain`. Anything else is `text/plain` for a template or a log and
`application/octet-stream` for an uploaded file — sending a firmware image as
text is how it arrives corrupted.

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
| `{{client.private_ip}}` | where the handset is on the local network |
| `{{client.public_ip}}` | where the site it sits behind is reached |
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
| `?display=oryk_provisioner` | the list | Clients, Profiles, Logs |
| `&client=<id>` | one client (`&client=` for a new one) | Client, Resources, Logs |
| `&profile=<id>` | one profile (`&profile=` for a new one) | Profile, Resources, Clients |
| `&profile=<id>&resource=<id>` | one file (`&resource=` for a new one) | Resource, Clients |

Every table is paginated, searchable and sortable server-side. Save, Delete
and Close are in the FreePBX action bar on every editor. A tab is an ordinary
link and only the pane asked for is rendered, so a tab with nothing behind it
yet — Resources on a profile nobody has written — is not drawn at all.

**Last Seen** on the Clients list is the last time the endpoint answered that
client with a 200 — a file served, a configuration rendered, or a log the phone
PUT received. It shows how long ago that was, with the exact timestamp on
hover, and the client's own page shows the timestamp itself. Sort it ascending
to bring the phones nothing has heard from to the top. A refused request is not
a sighting: a client that is switched off, or one asking for a file its profile
does not serve, is reaching the PBX and getting nothing, and that is what the
Logs tab is for.

**The phone's web interface** is one button on the Clients list, on the rows
that have a private address on them: it opens `http://<address>` in a new tab.
It is drawn from what is stored on the client and nothing more — the module
never connects to a phone, so the button is not a reachability check, and a row
with no address simply has no button. The address is validated when it is saved
precisely because it ends up in a link.

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

Four tables, all created by `install()` with `CREATE TABLE IF NOT EXISTS`.
Columns added after a table first existed are added by `src/Schema.php`, which
asks `information_schema` what is already there rather than trusting a
`dbversion`.

**`oryk_provisioner_clients`** — `id`, `mac` (unique, 12 lowercase hex),
`device_id`, `profile_id`, `token`, `enabled`, `last_seen`, `public_ip`,
`private_ip`, `created_at`, `updated_at`.
`enabled` is whether the endpoint answers this client at all; it defaults to 1,
so every client written before there was a switch is one nobody switched off.
`last_seen` is when the endpoint last answered this client with a 200, written
by the request that was answered. It is on the client rather than read out of
the provisioning log beside it, because that log is prunable — there is a Clear
button on two pages — and when a phone last checked in has to survive its
requests being thrown away. It is NULL until the first 200, so every client on
a site upgrading into this reads as never seen until its phone next asks.
`public_ip` and `private_ip` are where the phone is, as somebody wrote it
down; both are `VARCHAR(45)`, which is an IPv6 address at its longest, and both
are NULL until they are filled in. Nothing discovers them and nothing in the
module connects to them: `private_ip` is what the Clients list draws its link
to the handset from, which is why both are validated with `filter_var()` on the
way in rather than stored as typed.
`token` is a `password_hash()` of the client's token, shown as it stands
in the client editor and deliberately not indexed: it is verified against,
never looked up by, since a request already says who is asking.
`device_id` is a `VARCHAR(20)` because FreePBX `devices.id` is a string column,
and it keeps that name deliberately: it holds a FreePBX device id, which is the
one thing on the row that is still a device.

**`oryk_provisioner_profiles`** — `id`, `name` (unique), `enabled`,
`created_at`, `updated_at`. `enabled` is the same column a client has and
means the same thing one level up: a disabled profile serves nothing, so every
client assigned to it is refused. It defaults to 1, so profiles written before
there was a switch are ones nobody switched off.

**`oryk_provisioner_resources`** — `id`, `profile_id`, `name`, `type`,
`template` (LONGTEXT), `file_size`, `file_uploaded_at`, `created_at`,
`updated_at`. Unique on `(profile_id, name)`, and a plain key on `name` for the
by-name lookup a request with no client behind it makes.
`type` is `template`, `file` or `log`, and it is the only thing that says which
— it was inferred from `file_size` until 1.0.14, which meant a resource could
not be a file before a file was on it and could not be a log at all.
`file_size` is now a fact about the upload rather than the thing that decides;
the file itself lives in `ASTSPOOLDIR/repo`, named after the resource id.

**`oryk_provisioner_logs`** — `id`, `mac`, `filename`, `status`, `message`,
`method`, `ip`, `user_agent`, `created_at`. One row per request the endpoint
answered, written whether or not the MAC is one this module knows — which is
why there is no `client_id` on it and no foreign key, and why `mac` is 64 wide:
it also has to hold what was asked with when what was asked with is not a MAC
at all.

`uninstall()` leaves all four in place.

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

- **A client with no token is keyed on MAC address alone.** Anyone who can
  reach the URL and knows — or guesses — a MAC gets that client's rendered
  configuration, including `device.secret` if the template emits it. Giving the
  client a token closes that: the endpoint then answers it nothing without HTTP
  Basic credentials that verify against the stored hash. Nothing makes you set
  one, so a fleet provisioned without tokens is as open as it ever was —
  restrict who can reach `/provisioner/` at the network layer, and prefer
  HTTPS, since Basic credentials over plain http are credentials in the clear.
- **A token is not rate limited and there is no lockout.** A wrong one costs
  the caller a single bcrypt verification over an endpoint anyone can reach.
- **An uploaded File resource is served to a caller with no client behind it
  at all**, matched by name across every enabled profile — which is what lets a
  phone fetch firmware before anybody has written its client, and also means
  anyone who reaches the endpoint and knows the name can fetch it.
- **Failures are not uniform.** A 404 says which kind of failure it was
  ("… is not associated with anything", "… has no profile assigned",
  "… is disabled", "The … profile is disabled"), so a caller probing MACs can
  tell a known one from an unknown one.
- **Secrets are not masked anywhere in the UI.** The Render links serve the real
  rendered file, secret included.
- The provisioning log records metadata only — MAC, file, profile — never the
  rendered body or any parameter value.
- Sortable columns are whitelisted and mapped to SQL names before being written
  into a statement; everything else is bound.

---

## Not built yet

The larger design this is working towards, none of which exists in the code:

- **A token that *identifies* a client**, the way `/provisioner/{token}/{file}`
  would. The token there is today authenticates a client the request has
  already named by its MAC; identifying one by the token alone needs a lookup a
  `password_hash()` column cannot serve, so that scheme wants a second,
  digest-based column rather than this one. Uniform failures belong with it: a
  404 still says which kind of failure it was, so a caller probing MACs can
  tell a known one from an unknown one.
- **Per-client parameters** and the resolution order (module settings → schema
  defaults → template defaults → FreePBX/extension → client overrides). A client
  today is a MAC, a device and a profile; nothing overrides anything.
- **A bundled template library** for Yealink, Poly, Grandstream and a generic
  softphone. Every template is one you write, and a profile is set up one file
  at a time from empty — there is no seeding and no copying resources between
  profiles.
- **Copying resources between profiles, or seeding a new one.** A profile is
  set up one file at a time from empty.
- **A GraphQL API**, a `fwconsole` command, and module settings.
- **Pruning the provisioning log.** It grows by a row per file per boot per
  phone and nothing trims it; the Clear button on the Logs tabs is all there
  is.
- **Template filters, sections and content-type-aware escaping.**
- Backup and restore hooks are stubs.

---

## Code map

```
Oryk_provisioner.class.php   BMO: the contract FreePBX calls, an autoloader
                             for src/, and the AJAX dispatch table. Since
                             1.0.13 it is a thin adapter and nothing else
src/Service.php              what every subsystem is given: FreePBX, the
                             database, the four table names
src/Clients.php              |
src/Profiles.php             |  one per table
src/Resources.php            |
src/ProvisioningLog.php      |
src/Enabled.php              the enabled column, shared by two of them
src/Endpoint.php             answering a provisioning request, and ending it
src/Matcher.php              a filename is a MAC and a name, read both ways
src/Template.php             a placeholder, and what it resolves to
src/Previews.php             which filename does this phone ask this file by
src/Pages.php                which URL is which page
src/Navigator.php            the breadcrumb's levels
src/Counts.php               the row counts a tab is labelled with
src/Repo.php                 a directory this module keeps files in
src/FileRepo.php             where an uploaded resource file is kept
src/LogRepo.php              where a log a phone sent us is kept
src/Tokens.php               hashing a client's token, and checking one
src/Freepbx.php              the only file that asks FreePBX about a device
src/Mac.php                  a MAC as written, and as found in a filename
src/Schema.php               the tables, as they are added to
src/Installer.php            installing and uninstalling
src/Logs.php                 how this module writes to the FreePBX log
engine/provisioner.php       the endpoint: who is asking and what they asked
                             for, and nothing else
engine/.htaccess             rewrites the engine directory to provisioner.php
page.oryk_provisioner.php    one line into showPage()
views/admin.php              the list
views/client.php             the client editor
views/profile.php            the profile editor
views/resource.php           the resource editor
views/partials/navigator.php the breadcrumb every page is topped with
views/partials/tabs.php      the tab strip every page is laid out under
views/partials/counts.php    the badges on those tabs
views/partials/editor.php    the CSS and JS all three editors share
views/partials/logs.php      the provisioning log, as a table
views/partials/placeholders.php   the placeholder reference
```

AJAX commands, all authenticated through `ajax.php`: `listClients`,
`listProfiles`, `listResources`, `listLogs`, `saveClient`, `saveProfile`,
`saveResource`, `deleteClient`, `deleteProfile`, `deleteResource`,
`setClientEnabled`, `setProfileEnabled`, `uploadResourceFile`,
`deleteResourceFile`, `clearLogs`, `counts`. A new one has to be named in both
`ajaxRequest()` and `ajaxHandler()`.
