# The admin interface

*Oryk → Provisioner*. A list, five editors, a device page, a log entry page and a job page, told apart by which key the URL
carries. Every tab is in the address too, so a reload, a bookmark or a link from
elsewhere in the module lands where you were.

Every page is topped by the same bar of sections — Users, Devices, Clients,
Profiles, Services, Logs, Jobs, Bans, Overview, Settings — with the one you are in underlined, so any section is
one click away from anywhere. Under it is a row of searchable dropdowns —
Users, Devices, Clients, Profiles, Resources, Services, Logs, Bans, Jobs — narrowed to whatever you are
looking at: on a profile, Clients lists that profile's clients and Users the
people they belong to; on a client, Users and Profiles list its user and its profile,
Resources lists the files it is served, Logs its latest requests and Bans every
ban that would apply to it. On a ban, the other dropdowns list what that ban
covers. Services lists the services assigned to the users in view — the ones
ticked for them, not those that come with a pack — and on a service, Users
lists who is assigned it and the rest follow from their phones. Jobs lists the
[jobs](jobs.md) of the users in view, and on a service the jobs changes to it
made. The dropdown for the thing you are on
lists all of its kind, so you can switch straight to another one. A
dropdown's title, with its count, opens the table of exactly those: on a
client, **Profiles 1** opens the Profiles list showing only that client's
profile, with a **Show all** link back to the whole list (the section bar's
Profiles is the whole list too). The list page opens one section at a time
(`&tab=clients`, `&tab=profiles`, ...); the editors below have their own tabs,
which are views of that one row.

Over the section bar the module may show a **notice**: what to do next on a
PBX that is just starting -- add a first profile, then a first user, then a first client -- or
a warning: no **Hostname** is set, or Provisioning is **Open** and no profile
is named `Default`, so a sign-up whose vendor has no profile of its own would
be answered nothing. A
notice goes by itself once it no longer applies, and the next one takes its
place; its button leads to where the thing is done, and its × dismisses it
for every admin. Notices are shown on the module's pages only, never on the
dashboard. **Settings → Show Dismissed Notices Again** brings back the ones
that were dismissed and still apply.

| URL | Page | Tabs |
| --- | --- | --- |
| `?display=oryk_provisioner&tab=<section>` | a section's list | — |
| `&tab=<section>&scope=<kind>:<id>` | that list, narrowed to what a `user`, `client`, `profile`, `log`, `ban`, `service` or `job` row scopes; `holders:<slug>` is a service's users with those who have it through a pack | — |
| `&tab=services&source=module&kind=pack` | the Services list with its filters set; each is left out at its default (Available, All) | — |
| `&tab=jobs&state=failed&reason=&source=` | the Jobs list with its filters set; each is left out at its default (any) | — |
| `&tab=overview&scope=user:<extension>` or `client:<id>` | [Overview](#overview): everything tied to that user or client | — |
| `&client=<id>` | one client (`&client=` for a new one) | Client, Resources, Logs |
| `&profile=<id>` | one profile (`&profile=` for a new one) | Profile, Resources, Clients |
| `&profile=<id>&resource=<id>` | one file (`&resource=` for a new one) | Resource, Clients |
| `&user=<extension>` | one user (`&user=` for a new one) | User, Clients, Services |
| `&service=<slug>` | one service (`&service=` for a new one) | Service |
| `&ban=<id>` | one ban (`&ban=` for a new one) | Ban |
| `&device=<id>` | one FreePBX [device](#devices): the user it is on | Device |
| `&log=<id>` | one provisioning log entry, read-only | Log Entry |
| `&job=<id>` | one [job](jobs.md): its steps, Retry when it failed, Delete | Job |

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

**A client with no device to pick** can have one made: choose **-- Auto
Create --** as its Device and save. The module makes a PJSIP device on the
next free number, with no extension and no user, and puts the client on it; it
can register once Apply Config has run. Such a device is not on the Users
list, and has no number to be called on until it is given an extension in
FreePBX.

**Deleting a client** deletes the logs it sent and every Logs tab entry for its
MAC, including those from before it was added, and the bans naming it. The
trash can on the Clients list asks first, then deletes it there. A client on
a device is asked **Client Only** or **Client + Device**; the second deletes
the device too and keeps its extension.

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
whose name carries another device's MAC gets its filename and no buttons — the
link would answer for the wrong phone.

Each row has two ways to look at the file. **Open** fetches it from the
endpoint in a new tab, exactly as the phone does: the browser is asked for the
client's token when it has one, and the fetch counts as the client being seen.
**Render** opens the same body as plain text in a new tab, read through your
admin login, so it needs no token and is not logged. A firmware image or other
file too large or binary to show is given its size instead. **Download** saves the
same body, any size, under the filename this client asks for it by.

**Deleting a profile** that clients are still assigned to is refused rather than
cascading; its resources do cascade, since a resource has no existence apart
from the profile that serves it.

## Overview

Everything tied to one user or one client, on one page, so it can be cleaned
up: pick the user or the client from the dropdowns under the section bar and
the page reloads on it. The Overview button on the Users list leads
here, and from a user's or client's own page the section bar's **Overview**
opens on that row.

It is tables, the same ones the lists show:

| | For a user | For a client |
| --- | --- | --- |
| **User** | the user, with a link to its User Manager account | its user, which is kept |
| **Devices** | the FreePBX devices on its extension | — |
| **Clients** | every client on it, with the logs each has stored | the client itself |
| **Services** | the services it is assigned itself, not those a pack brings; a trash can unassigns one, **Edit** opens its Services tab | its user's |
| **Call history** | the calls it made or received | — |
| **Voicemail** | the messages in its mailbox, every folder | — |
| **Provisioning log** | the requests from its clients' MACs | the requests from its MAC |
| **Jobs** | its [jobs](jobs.md) | — |
| **Bans** | every ban naming it or applying to it | the same |

Each part can be removed where it is listed: a client, a job or a ban by its trash
can, a user's jobs all at once with **Clear** (what they did stays done), the log entries with **Clear These Entries**, a client's stored logs with
the trash can beside their count.

**Clear Call History** removes a user's calls and their recordings and keeps
the user. It is what deleting the user does to its history, so a call between
two extensions is removed from the other's history too, and there is no undo —
back up `asteriskcdrdb` first if the history matters. **Clear Voicemail**
deletes every message in the user's mailbox and keeps the mailbox and its
greetings, and each message has its own trash can; a phone's message-waiting light follows within a minute or so.

**Delete All**, in the action bar, removes the user or client and everything
listed for it in one go, after saying how much that is. For a user that is the
whole of [deleting a user](users.md#deleting) — extension, account, voicemail,
call history and recordings — plus its clients, their logs and the bans naming
it. There is no undo.

The Bans table says what Delete All does with each ban. **Removes**: the ban
names this client, its MAC or this user's extension. **Keeps**: the ban only
applies to it, through an address or a profile, and is about more than this
row — delete it from its own trash can if you mean to.

The Devices table's **Status** is whether Asterisk has the device registered
right now — Registered, Unreachable (registered, but not answering),
or Not registered — with the address it registered from on hover. In it, a trash can deletes that device; when a
client uses it you are asked whether to delete the **Device Only**, which
keeps the client with no device assigned, or **Device + Client**. Deleting the user's own device — the one
numbered like the extension — leaves the user on the Users list marked **no
device**, with its extension, account, voicemail and call history in place:
save the user to give it a device back, or Delete All to remove the rest.
Delete All on a user takes every device on its extension, with the clients
on each.

## Settings

The **Settings** tab holds the module's PBX-wide settings. They are the same
settings as **Settings → Advanced Settings → Oryk Provisioner**: change one in
either place and the other shows it. Save checks every value before writing
any, and stays on the tab. Under the settings, **Show Dismissed Notices Again**
brings back the [notices](#the-admin-interface) that were dismissed.

| Setting | What it is |
| --- | --- |
| **Hostname** (`ORYK_HOSTNAME`) | What phones register to: `{{server.host}}` in a template, and the From Domain when that is blank. Blank: a template gets the host each request arrived on, and From Domain this machine's hostname. |
| **From Domain** (`ORYK_FROM_DOMAIN`) | The domain users' endpoints put in the From header — see [From Domain](users.md#from-domain). Blank: the Hostname setting, or this machine's hostname, when that is a domain name; the field shows what blank comes to. |
| **Provisioning Server** (`ORYK_PROVISIONING_SERVER`) | Where phones fetch their files: `{{provisioning.server}}` in a template. A host, with a port and a path if it has them, and no `http://` or `https://` — `prov.example.com`, or `pbx.example.com/provisioner`. Blank: the Hostname setting, or the host each request arrived on, followed by `/provisioner`; the field shows what blank comes to. |
| **Provisioning** (`ORYK_PROVISIONING`) | Closed (the default): a request for MAC `000000000000` is treated like any other MAC. Open: that request is answered by its Basic credentials — see [Open provisioning](endpoint.md#open-provisioning). Disabled: every request to the endpoint is refused with a 503, unlogged. |
| **Deny After** (`ORYK_BAN_DENY_AFTER`) | Blank (the default): off. A number from 2 to 1000: a Banned ban that comes into force that many times — the **Times** column, whether fail2ban banned the address again or it was banned again here — becomes Deny. See [Repeat bans](bans.md#repeat-bans). |
| **Sign-up Context** (`ORYK_OPEN_CONTEXT`) | `lobby` (the default): every user open provisioning makes goes in the lobby the module writes — see [The lobby](endpoint.md#the-lobby). Another name is a context you provide; a `from-` one saves with a warning. Takes effect on Apply Config. |
| **Sign-ups per Minute** (`ORYK_OPEN_PER_MINUTE`) | 1: how many users open provisioning makes for one address (an IPv6 /64) in a minute. 0 is no limit. An Allow ban on the address lifts every limit. |
| **Sign-ups per Day** (`ORYK_OPEN_PER_DAY`) | 5: the same, in any 24 hours. |
| **Sign-ups per Day, PBX** (`ORYK_OPEN_PER_DAY_TOTAL`) | 0 (off): how many users open provisioning makes in any 24 hours from every address together. Reaching it puts a notice on the dashboard. |
| **Lobby Calls** (`ORYK_OPEN_CALLS`) | 1: calls one lobby extension places at once. 0 is no limit. Takes effect on Apply Config. |
| **Lobby Emergency Caller ID** (`ORYK_OPEN_EMERGENCY_CID`) | Blank: a sign-up's emergency caller id is its extension. Set it to a number an emergency operator can call back. Given to users as they sign up. |
| **Lobby Expiry** (`ORYK_OPEN_EXPIRE_DAYS`) | 0 (off): days a lobby user may go unseen before it is listed under **Expired** on the Users tab — see [The lobby](users.md#the-lobby). |
| **Fail2ban Sync** (`ORYK_FAIL2BAN_SYNC`) | Yes (the default): IP bans are kept in step with fail2ban every minute and on every save — see [Syncing with fail2ban](fail2ban.md). No: paused; nothing is read from or written to fail2ban, and nothing already there is undone. |


## Devices

**Devices** lists every FreePBX device — a user's own, the handsets on an
extension, and the ones on no extension that a client's **Auto Create** made —
with the user it is on and the client that uses it, each a link. Nothing is added here.

A trash can deletes the device, as **Delete** does on its page. When a client
uses it you are asked whether to delete the **Device Only**, which keeps the
client with no device assigned, or **Device + Client**. Only the device goes:
its extension, account, voicemail and call history stay.

**Edit** opens the device (`&device=<id>`), where the one thing to change is
its **User**: any extension, or **None**. On that page the dropdowns are
narrowed to the device: its user, the clients on it, and their profiles, logs
and bans. Elsewhere the **Devices** dropdown lists the devices of whatever you
are viewing — a user's are the ones on its extension, a client's the one it uses. Save moves the device at once and
raises Apply Config. A client on the device follows it, so it is counted as
the new user's from then on.

Moving a user's **own** device — the one numbered like its extension — to
another user is asked about first: that extension is left with no device, and
since its number is still held by a device that is now somebody else's, it is
no longer on the Users list until the device is put back on it.
