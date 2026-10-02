# The admin interface

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

## Settings

The **Settings** tab holds the module's PBX-wide settings. They are the same
settings as **Settings → Advanced Settings → Oryk Provisioner**: change one in
either place and the other shows it. Save checks every value before writing
any, and stays on the tab.

| Setting | What it is |
| --- | --- |
| **From Domain** (`ORYK_FROM_DOMAIN`) | The domain users' endpoints put in the From header — see [From Domain](users.md#from-domain). Blank: the PBX hostname, when that is a domain name; the field shows what blank comes to. |
| **Fail2ban Bans** (`ORYK_FAIL2BAN`) | Yes (the default): the [Bans](fail2ban.md) tab is shown. No: the tab, its pages and its commands are gone and the module asks fail2ban nothing. Switching it off does not uninstall the helper — see [Uninstalling fail2ban access](fail2ban.md#uninstalling-fail2ban-access). |
| **Provisioning** (`ORYK_PROVISIONING`) | Closed (the default): a request for MAC `000000000000` is treated like any other MAC. Open: the endpoint takes that request's Basic credentials as a User Manager login. One that logs in is answered with its default extension. A username no account holds creates a user with the next free number, as a blank Extension does in the Users editor, and gives its account that username and password, as the extension form's *Use Custom Username* does; the username also becomes the email when it is one. The SIP secret is generated, not the password. A username held under another password is a 401; a login with no Extension/User, or no User Manager, a 409. The user's client on an internal MAC (`02…`) is then found, or created — enabled, no profile, the credentials as its token — and its MAC returned alongside the extension; a client on a real phone's MAC is never reused. 201 when either was created. The request is written to the provisioning log. Disabled: every request to the endpoint is refused with a 503, unlogged. |
