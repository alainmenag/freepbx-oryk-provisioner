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

```bash
cd /var/www/html/admin/modules
git clone https://github.com/alainmenag/freepbx-oryk-provisioner.git oryk_provisioner
sudo fwconsole chown
sudo fwconsole ma install oryk_provisioner
```

### You can also:

1. Download the latest release from https://github.com/alainmenag/freepbx-oryk-provisioner/releases
2. Upload it via your FreePBX module admin
3. Install the module via the moudle admin

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
- Manages **users** — an extension, its User Manager account and its SIP
  device as one number — from a Users tab: create, rename, renumber and delete
  them, with voicemail, UCP access, call history and the clients provisioned
  for them following along. This replaces `oryk_connect` for Extension/User
  devices; see [Users](#users).
- Sets a **From Domain** on every user's endpoint, from the user, from the
  **Settings** tab (also in *Advanced Settings*), or from the PBX hostname.
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

*Oryk → Provisioner*. A list and five editors, told apart by which key the URL
carries. Every tab is in the address too, so a reload, a bookmark or a link from
elsewhere in the module lands where you were.

| URL | Page | Tabs |
| --- | --- | --- |
| `?display=oryk_provisioner` | the list | Clients, Profiles, Users, Logs, Bans, Settings |
| `&client=<id>` | one client (`&client=` for a new one) | Client, Resources, Logs |
| `&profile=<id>` | one profile (`&profile=` for a new one) | Profile, Resources, Clients |
| `&profile=<id>&resource=<id>` | one file (`&resource=` for a new one) | Resource, Clients |
| `&user=<extension>` | one user (`&user=` for a new one) | User, Clients |
| `&jail=<jail>&ban=<ip>` | one fail2ban ban (`&ban=` for a new one) | Ban |

Every table is paginated, searchable and sortable server-side. Save, Delete
and Close are in the FreePBX action bar on every editor. Save leaves you on
what you are editing: a change reloads the same page with it written, and a new
client, profile or resource opens on its own page — which is where the rest of
it is filled in, since a resource's file and template and a profile's Resources
tab exist only once the row does. Close is what goes back to the list. A tab is an ordinary
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

## Settings

The **Settings** tab holds the module's PBX-wide settings. They are the same
settings as **Settings → Advanced Settings → Oryk Provisioner**: change one in
either place and the other shows it. Save checks every value before writing
any, and stays on the tab.

| Setting | What it is |
| --- | --- |
| **From Domain** (`ORYK_FROM_DOMAIN`) | The domain users' endpoints put in the From header — see [From Domain](#from-domain). Blank: the PBX hostname, when that is a domain name; the field shows what blank comes to. |
| **Fail2ban Bans** (`ORYK_FAIL2BAN`) | Yes (the default): the [Bans](#bans) tab is shown. No: the tab, its pages and its commands are gone and the module asks fail2ban nothing. Switching it off does not uninstall the helper — see [Uninstalling fail2ban access](#uninstalling-fail2ban-access). |

---

## Users

The **Users** tab lists every PJSIP extension that is its own device — the ones
this module makes, and the ones made in FreePBX's Extensions page, which have
the same shape. A user is not stored by this module at all: it is the Core
device, extension, User Manager account and mailbox, kept in step.

| Field | What it is |
| --- | --- |
| **Extension** | The device id, the extension and the User Manager username. Blank on a new user: the next free number in the `999…` range, ten digits. Typed: digits only, at most ten, and not held by any device, extension or User Manager account, or the save is refused and nothing is written. Changed: the user is renumbered. |
| **Name** | The device description, extension name and User Manager display name. Blank: the number. |
| **Email** | The User Manager account's email (and its welcome email) and the voicemail email. |
| **From Domain** | See below. Blank follows the PBX; the field shows what blank comes to. |
| **Secret** | The SIP password. Never shown. Blank on a new user: generated; on an existing one: unchanged. |

Every save turns SDES media encryption on and applies the configuration, so
it takes as long as *Apply Config*. Settings you gave an extension in FreePBX
that this form has no field for are kept.

The user's **Clients** tab lists the phones provisioned for it, and its **Add
Client** opens a new client already pointed at this user. The client editor
links back to its user.

### Renumbering

Change the Extension and save. The extension keeps its settings, the User
Manager account keeps its password, groups and UCP settings, the mailbox and
its messages move, UCP access moves, handsets pointed at the old number and
**every client pointed at it** are repointed, and the call history is rewritten
to the new number (`src`, `dst`, `cnum`, `clid`, channel names — recording file
names are left alone so they still match the file on disk). The old number is
given up only once the extension exists on the new one. Ring groups, queues and
other destinations naming the old number are **not** updated.

### Deleting

> [!CAUTION]
> Deleting a user is permanent. The device goes, and — once no other device
> points at the extension — the extension, its User Manager account (when this
> module made it), its UCP assignments, and **its call history and recordings**
> in `cdr`, `transient_cdr`, `replicate_cdr` and `cel`. A call between two
> extensions belongs to both, and is removed from the other's history too. Back
> up `asteriskcdrdb` first if the history matters.

Clients pointed at a deleted user are kept, with no device. Delete asks first,
and says how many clients that is.

### From Domain

Each user's PJSIP endpoint gets a `from_domain`, written to
`/etc/asterisk/pjsip.endpoint_custom_post.conf` as a `[<ext>](+)` section that
adds to the endpoint FreePBX generates. First answer wins:

1. the user's own **From Domain**;
2. the PBX-wide **From Domain** (`ORYK_FROM_DOMAIN`), the normal place to set
   it — on the provisioner's **Settings** tab, or in **Settings → Advanced
   Settings → Oryk Provisioner**, which is the same setting;
3. the PBX hostname, only when it is a real domain name.

Nothing resolved takes it off the endpoint. A changed PBX-wide value reaches a
user on its next save. The file is shared with other modules and only this
module's lines are touched; it has to be writable by the web user, and a
failure is logged while the user still saves.

---

## Bans

The **Bans** tab lists what fail2ban has banned, in every jail — on a FreePBX
box usually `asterisk` (SIP) and `sshd`. Each row is one address in one jail,
with when it was banned, when it expires, and the **client** whose Public IP it
is, when it is one: a site whose phones keep failing to register is the usual
reason anyone opens this tab. **Unban** lifts a ban now. **Add Ban** bans one
address in one jail for that jail's own bantime, as though fail2ban had banned
it. A ban cannot be edited; its page shows it, with Unban and Close.

The tab can be switched off — and back on — with **Fail2ban Bans** on the
[Settings](#settings) tab (`ORYK_FAIL2BAN`, also in Advanced Settings). Off, the
tab is not drawn, a ban's page goes back to the module page, the AJAX commands
refuse, and nothing calls sudo. It is on by default; a PBX that has the new
files but has not had `fwconsole ma install` run yet treats it as on.

Adding a ban refuses the address you are connected from, loopback, and the
PBX's own addresses. One written on a client as its Public IP is allowed after
a warning, since it blocks every phone at that site. One address at a time; no
ranges.

### Installing fail2ban access

fail2ban only answers root, and the GUI runs as the web user, so the module
reaches it through a small helper that sudo lets the web user run. Until that
is in place the Bans tab shows what is missing and the command to run instead of
a table.

1. fail2ban has to be installed and running:

   ```bash
   sudo apt install fail2ban
   sudo systemctl enable --now fail2ban
   sudo fail2ban-client status          # lists the jails, e.g. asterisk, sshd
   ```

2. Put your own address in `ignoreip` in `/etc/fail2ban/jail.local`, so a wrong
   ban on `sshd` cannot lock you out, and `sudo systemctl reload fail2ban`.

3. Once on each PBX, as root:

   ```bash
   sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup
   ```

4. Open the Bans tab, or press **Check again** on it. The list appears.

The script checks fail2ban, Python, sudo and the web user
(`AMPASTERISKWEBUSER`, normally `asterisk`), then installs two things and
nothing else:

| | |
| --- | --- |
| `/usr/local/sbin/oryk-fail2ban` | a root-owned copy of the module's `bin/oryk-fail2ban`. It can list jails and bans and ban or unban one address in one jail, and refuses anything else |
| `/etc/sudoers.d/oryk_provisioner` | `asterisk ALL=(root) NOPASSWD: /usr/local/sbin/oryk-fail2ban` — that one file, and nothing else, as root. Checked with `visudo` before it is used |

It then runs the helper as the web user and prints **OK** with the jails it
found:

```
  existing sudo configuration                  ok
  installing /etc/sudoers.d/oryk_provisioner   ok
  ...
  the web user can reach fail2ban              ok (asterisk, sshd)

OK. Reload the Bans tab.
```

It is safe to run again, and has to be run again after a module upgrade that
changes the helper — the tab says so (*not the one this version of the module
ships*). `fwconsole ma install` or `upgrade`, run as root, runs the script for
you and prints what it said; from Module Admin in the GUI it cannot (no root),
and the install message gives the command instead.

If it stops, it says at which step and why, and changes nothing after it:

| stops at | what to do |
| --- | --- |
| *fail2ban installed* / *fail2ban running* | `sudo apt install fail2ban`, `sudo systemctl enable --now fail2ban`, run it again |
| *existing sudo configuration* | another file in `/etc/sudoers.d` fails `visudo -c`, and sudo takes no new rule until it passes. A wrong mode or owner (`bad permissions, should be mode 0440`) is fixed by the script — the same as `sudo chmod 0440 /etc/sudoers.d/<file>` — and listed as `fixed:`. Anything else is printed with its file and line: correct it with `sudo visudo -f /etc/sudoers.d/<file>`, check with `sudo visudo -c`, run it again |
| *the web user can reach fail2ban* | the line printed is what sudo said; `--check` shows every check at once |

### Checking fail2ban access

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup --check
```

Runs every check and changes nothing: fail2ban, the whole sudo configuration,
the installed helper (present, owned by root, the version this module ships),
the sudo rule, and a call as the web user. Ends with **OK**, or with how many
problems there are — running the script without `--check` fixes them.

By hand, as the web user would:

```bash
sudo -u asterisk sudo -n /usr/local/sbin/oryk-fail2ban check
sudo -u asterisk sudo -n /usr/local/sbin/oryk-fail2ban list asterisk
```

### Uninstalling fail2ban access

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup --remove
```

Deletes `/usr/local/sbin/oryk-fail2ban` and `/etc/sudoers.d/oryk_provisioner`,
and nothing else: fail2ban, its jails and every ban stay exactly as they are,
and the web user can no longer reach it. The Bans tab goes back to the setup
instructions. To hide the tab as well, set **Fail2ban Bans** to No on the
Settings tab; switching it off alone leaves the helper and sudo rule installed,
unused. `fwconsole ma uninstall oryk_provisioner`, run as root, does the
same; uninstalled from the GUI it cannot, so run `--remove` first — the script
is inside the module directory, and goes with it.

Any file the script fixed the mode or owner of (`fixed:` in its output) is left
fixed: that is the mode sudo expects, and `--remove` does not put it back.

### Testing the setup from scratch

Keep a root shell (`sudo -i`) open in another window while you do this.

```bash
S=/var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup

sudo bash $S --remove          # the tab: helper not installed
sudo bash $S --check           # every missing piece listed, nothing changed
sudo bash $S                   # installed; ends with OK
sudo bash $S --check           # every line ok
```

Then from the tab: **Add Ban** `203.0.113.7` in `asterisk` (a documentation
address, so nobody real), see it with `sudo fail2ban-client status asterisk`,
**Unban** it, and check it has gone. Banning the address you are connected from
is refused.

Every load of the module page asks fail2ban for the badge, so each writes a sudo
line to the auth log.

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

Each tagged version is published as `oryk_provisioner-<version>.zip` on the
repository's Releases page, ready to upload in Module Admin. To cut one, bump
`<version>` in `module.xml`, merge, then push a tag of the same number
(`1.1.2` or `v1.1.2`); the build refuses a tag that does not match.

`install()` registers the module's settings in Advanced Settings (keeping any
value already there) and adds indexes on `devices.id`, `devices.user` and
`userman_users.email` for the Users tab. Back up the FreePBX database before
installing or upgrading. Uninstalling removes the module's settings with the
module, as FreePBX does with every module's settings.

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

The **Bans** tab needs one more step, as root, that a module install from the
GUI cannot do — see [Installing fail2ban access](#installing-fail2ban-access). Uninstalling from the
GUI leaves the fail2ban helper and its sudo rule in place: run
[`--remove`](#uninstalling-fail2ban-access) first.

### Coming from Oryk Connect

This module replaces `oryk_connect` for **Extension/User** devices. Nothing
moves: users, extensions, accounts and mailboxes are Core's, so every one Connect
made is already on the Users tab. Both can be installed at once; they write the
same endpoint file under the same lock.

Before removing Connect, check it has nothing left only it can manage:

```sql
SELECT id, description FROM devices WHERE tech = 'rtsp';
SELECT s.data AS kind, COUNT(*) FROM devices d
  JOIN sip s ON s.id = d.id AND s.keyword = 'kind' GROUP BY s.data;
```

**RTSP feeds need Connect's driver** — do not remove Connect while any
`tech = 'rtsp'` device exists. Handsets and softphones are plain PJSIP devices
and keep working; nothing in Oryk edits them afterwards (a handset's place here
is a client).

Then:

1. `fwconsole ma upgrade oryk_provisioner` — this takes the From Domain setting
   over.
2. `fwconsole setting ORYK_FROM_DOMAIN` — the value you had in Connect.
3. Save one user from the Users tab, and check its section in
   `pjsip.endpoint_custom_post.conf` and that nothing else in the file changed.
4. Remove Connect (`rm -rf /var/www/html/admin/modules/oryk_connect`, since
   Connect is marked non-uninstallable), then `fwconsole reload`.
5. `fwconsole setting ORYK_FROM_DOMAIN` again. If it is empty, set it again.

Bookmarks to `?display=oryk_connect` become `?display=oryk_provisioner&tab=users`.

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
- **Deleting a user deletes its call history and recordings** — see
  [Deleting](#deleting).
- The provisioning log records metadata only — MAC, file, profile — never the
  rendered body or any parameter value.
- Sortable columns are whitelisted and mapped to SQL names before being written
  into a statement; everything else is bound.
- **The Bans tab is root access, narrowed.** The sudo rule lets the web user run
  one root-owned file, which checks every argument itself and can do nothing
  but list, ban and unban. Anyone who can use the FreePBX admin GUI can ban or
  unban any address — including on `sshd`. Put your own address in `ignoreip`
  in `/etc/fail2ban/jail.local`.

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
- **A GraphQL API** and a `fwconsole` command.
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
                             database, the manager, the four table names
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
src/Settings.php             the module's PBX-wide settings, and the Settings tab
src/Users.php                saving, deleting and listing a user
src/NumberAllocator.php      which numbers are free, and the next one
src/ExtensionRenumberer.php  moving a user to another number, in order
src/ExtensionManager.php     the Core extension and its astdb keys
src/UsermanManager.php       the User Manager account behind a user
src/VoicemailManager.php     mailboxes, their aliases, what dials them
src/UcpAssignments.php       what a UCP account may open
src/CdrHistory.php           moving and removing a number's call history
src/EndpointSettings.php     the From Domain, and where it is written
src/AsteriskConfig.php       an Asterisk config file, edited in place
src/Fail2ban.php             the only file that asks fail2ban, through the helper
src/Bans.php                 listing, adding and lifting a ban
bin/oryk-fail2ban            the root helper the sudo rule allows
bin/oryk-fail2ban-setup      installs that helper and the rule; --check, --remove
tests/smoke.php              standalone checks: php tests/smoke.php
src/Logs.php                 how this module writes to the FreePBX log
engine/provisioner.php       the endpoint: who is asking and what they asked
                             for, and nothing else
engine/.htaccess             rewrites the engine directory to provisioner.php
page.oryk_provisioner.php    one line into showPage()
views/admin.php              the list
views/client.php             the client editor
views/profile.php            the profile editor
views/resource.php           the resource editor
views/user.php               the user editor
views/ban.php                one ban, or a new one
views/partials/navigator.php the breadcrumb every page is topped with
views/partials/tabs.php      the tab strip every page is laid out under
views/partials/counts.php    the badges on those tabs
views/partials/settings.php  the Settings tab, drawn from Settings::fields()
views/partials/editor.php    the CSS and JS every editor shares
views/partials/logs.php      the provisioning log, as a table
views/partials/placeholders.php   the placeholder reference
```

AJAX commands, all authenticated through `ajax.php`: `listClients`,
`listProfiles`, `listResources`, `listLogs`, `saveClient`, `saveProfile`,
`saveResource`, `deleteClient`, `deleteProfile`, `deleteResource`,
`setClientEnabled`, `setProfileEnabled`, `uploadResourceFile`,
`deleteResourceFile`, `clearLogs`, `counts`, `listUsers`, `saveUser`,
`deleteUser`, `saveSettings`, `listBans`, `saveBan`, `deleteBan`. A new one
has to be named in both
`ajaxRequest()` and `ajaxHandler()`.
