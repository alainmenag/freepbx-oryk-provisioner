# The admin interface

*Oryk → Provisioner*. A list, five editors and a log entry page, told apart by which key the URL
carries. Every tab is in the address too, so a reload, a bookmark or a link from
elsewhere in the module lands where you were.

Every page is topped by the same bar of sections — Users, Clients, Profiles,
Logs, Bans, Overview, Settings — with the one you are in underlined, so any section is
one click away from anywhere. Under it is a row of searchable dropdowns —
Users, Clients, Profiles, Resources, Logs, Bans — narrowed to whatever you are
looking at: on a profile, Clients lists that profile's clients and Users the
people they belong to; on a client, its user and its profile are already picked,
Resources lists the files it is served, Logs its latest requests and Bans every
ban that would apply to it. On a ban, the other dropdowns list what that ban
covers. The dropdown for the thing you are on
lists all of its kind, so you can switch straight to another one. A
dropdown's title, with its count, opens the table of exactly those: on a
client, **Profiles 1** opens the Profiles list showing only that client's
profile, with a **Show all** link back to the whole list (the section bar's
Profiles is the whole list too). The list page opens one section at a time
(`&tab=clients`, `&tab=profiles`, ...); the editors below have their own tabs,
which are views of that one row.

| URL | Page | Tabs |
| --- | --- | --- |
| `?display=oryk_provisioner&tab=<section>` | a section's list | — |
| `&tab=<section>&scope=<kind>:<id>` | that list, narrowed to what a `user`, `client`, `profile`, `log` or `ban` row scopes | — |
| `&tab=overview&scope=user:<extension>` or `client:<id>` | [Overview](#overview): everything tied to that user or client | — |
| `&client=<id>` | one client (`&client=` for a new one) | Client, Resources, Logs |
| `&profile=<id>` | one profile (`&profile=` for a new one) | Profile, Resources, Clients |
| `&profile=<id>&resource=<id>` | one file (`&resource=` for a new one) | Resource, Clients |
| `&user=<extension>` | one user (`&user=` for a new one) | User, Clients |
| `&ban=<id>` | one ban (`&ban=` for a new one) | Ban |
| `&log=<id>` | one provisioning log entry, read-only | Log Entry |

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

**Deleting a client** deletes the logs it sent and every Logs tab entry for its
MAC, including those from before it was added, and the bans naming it. The
trash can on the Clients list asks first, then deletes it there.

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
the page reloads on it. The magnifying glass on the Users list leads
here, and from a user's or client's own page the section bar's **Overview**
opens on that row.

It is tables, the same ones the lists show:

| | For a user | For a client |
| --- | --- | --- |
| **User** | the user, with a link to its User Manager account | its user, which is kept |
| **Devices** | the FreePBX devices on its extension | — |
| **Clients** | every client on it, with the logs each has stored | the client itself |
| **Call history** | the calls it made or received | — |
| **Voicemail** | the messages in its mailbox, every folder | — |
| **Provisioning log** | the requests from its clients' MACs | the requests from its MAC |
| **Bans** | every ban naming it or applying to it | the same |

Each part can be removed where it is listed: a client or a ban by its trash
can, the log entries with **Clear These Entries**, a client's stored logs with
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
or Not registered — with the address it registered from on hover. In it, a trash can deletes that device and nothing
else: a client using it is kept, with no device assigned. Deleting the user's own device — the one
numbered like the extension — leaves the user on the Users list marked **no
device**, with its extension, account, voicemail and call history in place:
save the user to give it a device back, or Delete All to remove the rest.
When another device is on a user's extension, the page says so: deleting the
user then removes its own device and clients, and the extension, account,
voicemail and history stay.

## Settings

The **Settings** tab holds the module's PBX-wide settings. They are the same
settings as **Settings → Advanced Settings → Oryk Provisioner**: change one in
either place and the other shows it. Save checks every value before writing
any, and stays on the tab.

| Setting | What it is |
| --- | --- |
| **Hostname** (`ORYK_HOSTNAME`) | What phones register to: `{{server.host}}` in a template, and the From Domain when that is blank. Blank: a template gets the host each request arrived on, and From Domain this machine's hostname. |
| **From Domain** (`ORYK_FROM_DOMAIN`) | The domain users' endpoints put in the From header — see [From Domain](users.md#from-domain). Blank: the Hostname setting, or this machine's hostname, when that is a domain name; the field shows what blank comes to. |
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
