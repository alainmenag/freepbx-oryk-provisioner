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

The list's **Clients** column counts the phones provisioned for each user,
**Secure** says whether media encryption is on, **Context** names the context
its calls are placed in, as it is written — `lobby` for users open
provisioning made — and **Last Seen** says when any of its phones was last
answered. A row has two buttons: one opens the user's
[Overview](admin.md#overview), which is also where a user is deleted from the
list, and **Ext.** opens it in FreePBX's Extensions page.

The user's **Clients** tab lists the phones provisioned for it, and its **Add
Client** opens a new client already pointed at this user. The client editor
links back to its user.

## The lobby

Users made by [open provisioning](endpoint.md#the-lobby) are in the lobby:
internal calls and emergency routes only. Sort the Users list by **Context**
to bring them together.

A user's **Context** is shown on its page and changed in Extensions, not
here. To let a lobby user out, set its context there (`from-internal` for an
ordinary extension) and Apply Config: it can then use your outbound routes
and place calls without the lobby's limit. Two things the sign-up set are not
undone by that: transfers stay off until you **Save** the user once on its
page here, and its UCP login stays refused until you allow it in User
Manager.

Nothing deletes a lobby user on its own, and the Users list has no filter
for the ones that have gone quiet: sort it by **Last Seen** to find them, and
delete each from its Overview. **Settings → Lobby Expiry**
(`ORYK_OPEN_EXPIRE_DAYS`) still says how many unseen days make a lobby user
expired, but no page lists expired users at present.

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
> Deleting a user is permanent. Every device on its extension goes, and the
> extension, its User Manager account (when this
> module made it), its UCP assignments, and **its call history and recordings**
> in `cdr`, `transient_cdr`, `replicate_cdr` and `cel`. A call between two
> extensions belongs to both, and is removed from the other's history too. Back
> up `asteriskcdrdb` first if the history matters.

Every client on any of a deleted user's devices is deleted with it, whatever its MAC,
along with the logs it sent and its entries on the Logs tab. Delete on a user's page asks first.

The Overview button on the Users list opens [Overview](admin.md#overview) on that
user: its clients, log entries, stored phone logs and bans are listed,
any of them can be removed on its own, and **Delete All** removes the user
with all of it — including the bans naming its extension or its clients'
MACs, which a plain delete leaves.

## Services

A user's **Services** tab lists every [service](concepts.md) in two lists,
**Service Packs** and **Services**, each with a box: tick it to assign the
service to the user, untick it to take it away. Nothing is saved as you tick.
A changed row is marked *to assign* or *to unassign*, and **Save** in the
action bar asks first, listing what will be assigned and unassigned, what the
user gains and loses -- with what that does on the PBX, where it does
something: losing Voicemail removes the mailbox -- and anything unassigned
that the user keeps through another pack. **Reset** puts the boxes back. There is no Apply Config.

Assigning a service pack gives the user every service under it; those are
marked *included in* the pack, with a half-filled box, and stay with the user
for as long as the pack is assigned and holds them. One can still be ticked on
its own, and then stays when the pack goes. The button at the end of a pack's
row, which counts what it gives, opens that list; click the line above the
list to show it as a tree, pack inside pack, and again to go back. Both follow
the boxes as they are ticked, before anything is saved.

Renumbering a user keeps its services, deleting it removes them, and deleting
a service takes it off every user.

Everything saved at once is one change: a [job](jobs.md), run straight away,
when it changes what the user has. Swapping one pack for another in a single
save leaves alone the services both give. A service whose job is queued,
running or has failed says so beside it, with a link to the job.

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
