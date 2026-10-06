# Users

The **Users** tab lists every extension — the ones this module makes, and the
ones made in FreePBX's Extensions page. A user is not stored by this module at
all: it is the Core extension, its PJSIP device, User Manager account and
mailbox, kept in step.

An extension whose device has been deleted stays on the list, marked **no
device**: no phone can register as it, and its Context, Email and Secure are
blank, since the device held them. **Save** on its page gives it a device back
on the same number (in `from-internal`, with a new secret unless you type
one); **Delete**, or Overview's Delete All, removes what is left. An extension
whose number belongs to a device of another kind — a DAHDI line, say — is not
listed.

| Field | What it is |
| --- | --- |
| **Extension** | The device id, the extension and the User Manager username. Blank on a new user: the next free number in the `999…` range, ten digits. Typed: digits only, at most ten, and not held by any device, extension or User Manager account, or the save is refused and nothing is written. Changed: the user is renumbered. |
| **Name** | The device description, extension name and User Manager display name. Blank: the number. |
| **Email** | The User Manager account's email (and its welcome email) and the voicemail email. |
| **From Domain** | See below. Blank follows the PBX; the field shows what blank comes to. |
| **Secret** | The SIP password. Never shown. Blank on a new user: generated; on an existing one: unchanged. |

Every save turns SDES media encryption on and raises FreePBX's **Apply
Config** bar, as a save in Core's Extensions page does: the change reaches
Asterisk when you apply it. So does a delete. Settings you gave an extension
in FreePBX that this form has no field for are kept.

The list's **Context** column says where each user's calls are placed —
*Lobby* for users open provisioning made — and **Last Seen** when any of its
phones was last answered.

The user's **Clients** tab lists the phones provisioned for it, and its **Add
Client** opens a new client already pointed at this user. The client editor
links back to its user.

## The lobby

Users made by [open provisioning](endpoint.md#the-lobby) are in the lobby:
internal calls and emergency routes only. The **Lobby** choice above the
Users table lists them.

A user's **Context** is shown on its page and changed in Extensions, not
here. To let a lobby user out, set its context there (`from-internal` for an
ordinary extension) and Apply Config: it can then use your outbound routes
and place calls without the lobby's limit. Two things the sign-up set are not
undone by that: transfers stay off until you **Save** the user once on its
page here, and its UCP login stays refused until you allow it in User
Manager.

With **Settings → Lobby Expiry** (`ORYK_OPEN_EXPIRE_DAYS`) above 0, a lobby
user whose phone has not been answered for that many days — or never, and it
signed up that long ago — is listed under **Expired**. **Delete listed**
deletes those, as Delete would, after asking; one whose phone was seen in the
meantime is skipped. Nothing is deleted on its own.

## Renumbering

Change the Extension and save. The extension keeps its settings, the User
Manager account keeps its password, groups and UCP settings, the mailbox and
its messages move, UCP access moves, handsets pointed at the old number and
**every client pointed at it** are repointed, and the call history is rewritten
to the new number (`src`, `dst`, `cnum`, `clid`, channel names — recording file
names are left alone so they still match the file on disk). The old number is
given up only once the extension exists on the new one. Ring groups, queues and
other destinations naming the old number are **not** updated.

## Deleting

> [!CAUTION]
> Deleting a user is permanent. The device goes, and — once no other device
> points at the extension — the extension, its User Manager account (when this
> module made it), its UCP assignments, and **its call history and recordings**
> in `cdr`, `transient_cdr`, `replicate_cdr` and `cel`. A call between two
> extensions belongs to both, and is removed from the other's history too. Back
> up `asteriskcdrdb` first if the history matters.

Every client pointed at a deleted user is deleted with it, whatever its MAC,
along with the logs it sent and its entries on the Logs tab. Delete on a user's page asks first.

The pair of binoculars on the Users list opens [Overview](admin.md#overview) on that
user: its clients, log entries, stored phone logs and bans are listed,
any of them can be removed on its own, and **Delete All** removes the user
with all of it — including the bans naming its extension or its clients'
MACs, which a plain delete leaves.

## From Domain

Each user's PJSIP endpoint gets a `from_domain`, written to
`/etc/asterisk/pjsip.endpoint_custom_post.conf` as a `[<ext>](+)` section that
adds to the endpoint FreePBX generates. First answer wins:

1. the user's own **From Domain**;
2. the PBX-wide **From Domain** (`ORYK_FROM_DOMAIN`), the normal place to set
   it — on the provisioner's **Settings** tab, or in **Settings → Advanced
   Settings → Oryk Provisioner**, which is the same setting;
3. the **Hostname** setting (`ORYK_HOSTNAME`), unless it is an address;
   otherwise the PBX's own hostname -- either only when it is a real domain
   name.

Nothing resolved takes it off the endpoint. A changed PBX-wide value reaches a
user on its next save. The file is shared with other modules and only this
module's lines are touched; it has to be writable by the web user, and a
failure is logged while the user still saves.
