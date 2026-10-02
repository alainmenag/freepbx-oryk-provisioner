# Architecture

How the module is put together, and why. `README.md` and `docs/` are the
operator-facing account of what it does; `CLAUDE.md` is the working brief for
an agent editing it. This file is the one to read before changing behaviour.

The source carries the *local* half of this: an invariant a caller can get
wrong, a consequence worth knowing, the reason two expressions must agree. The
big picture, the schema rationale and the decisions that shaped it are here, so
nobody has to read twenty files to reconstruct them.

---

## The model

A **client** is a MAC address, optionally a FreePBX device, optionally a
**profile**. A profile serves **resources**. A resource is a filename and a
**type**: a *template* rendered for the client that asked, a *file* handed over
as stored (firmware, ringtones), or a *log* the phone PUTs back.

A phone asks `https://<pbx>/provisioner/0004f282e824-phone.cfg`. Its MAC names
a client, the client names a profile, the profile has a resource whose name
matches `phone.cfg`, and that resource is rendered and served.

Everything else is that sentence with the edge cases filled in.

A **user** is the other half of the module: a pjsip device that is its own
extension, managed from the Users tab. A client's device is usually one. See
[Users](#users). A **ban** is fail2ban's -- one address in one jail -- shown
and changed from the Bans tab. See [Bans](#bans). Neither is stored in this
module's tables.

## Request flow

```
phone
  -> engine/.htaccess          rewrites anything under engine/ to provisioner.php
  -> engine/provisioner.php    who is asking (MAC) + what they asked for (last
                               path segment). Bootstraps FreePBX directly:
                               freepbx_auth=false, restrict_mods=true.
                               MAC 000000000000 with ORYK_PROVISIONING=OPEN:
                               Basic credentials are a User Manager login;
                               they find or make a user (custom username on
                               its account) via Users::findOrCreate(), then its
                               internal-MAC client via
                               Clients::findOrCreateForDevice(); answered
                               with its extension as JSON here, and ends
  -> Oryk_provisioner::serve() / ::receive()      thin passthrough
  -> Endpoint::resolveRequest()                   decides
  -> Endpoint::answer()                           logs, sets status, sends, exits
```

`resolveRequest()` is the whole of the decision and is deliberately callable
without the exit -- previews, console commands and tests use it. **Its order is
the security of the thing:**

1. disabled client -> refused
2. disabled profile -> refused
3. match a resource of the client's profile by name
4. no client, or no profile: the resources declared `file`, matched by name
   exactly (this is how firmware is fetched by a phone that sends no MAC)
5. token, when the client has one -> 401
6. the resource's `type` decides what the answer is

The token is asked for **after** a match and **before** the type, so a 401
cannot tell an unauthenticated caller which files exist. Step 4 is files only:
a template with no client behind it has no values to render against, so the
phone would get a config that parses and is wrong.

**Known exposure, inherent to MAC-based provisioning.** Anyone who reaches the
URL and knows a MAC gets that client's config, `device.secret` included, unless
the client has a token. An uploaded file can be fetched by anyone who knows what
it is called. Restrict the URL at the network layer until the token scheme is
mandatory.

## Matching a filename

`Matcher` reads a filename both ways, and that is the module's central trick.

| resource name | phone asks for | how it matches |
| --- | --- | --- |
| `phone.cfg` | `0004f282e824-phone.cfg` | MAC taken off the front (`resourceSuffix()`), tail compared |
| `.cfg` | `0004f282e824.cfg` | same; the dot of an extension stays with the name |
| `{{device.mac}}-phone.cfg` | `0004f282e824-phone.cfg` | name rendered against this client, compared whole |
| `000000000000-directory.xml` | the same | matched literally -- that MAC is not this client's |

A name written out in full wins over a bare tail. `resourceRequest()` is the
same thinking backwards and is what every Render link is drawn from -- a name
carrying *another* device's MAC gets no link, because the endpoint reads the MAC
out of the path in preference to `?mac=`, so the link would answer for the wrong
phone.

## Files

```
Oryk_provisioner.class.php   BMO contract, src/ autoloader, AJAX dispatch table.
                             A thin adapter since 1.0.13 -- no behaviour.
page.oryk_provisioner.php    one line into showPage()
engine/provisioner.php       the anonymous endpoint a phone reaches
engine/.htaccess             rewrites everything under engine/ to provisioner.php
bin/                         the fail2ban helper and its setup script -- see Bans
src/                         35 files, namespace FreePBX\Modules\Oryk_Provisioner
tests/                       smoke.php and the stubs it runs against
views/                       one view per page, plus views/partials/
```

`src/`, roughly in dependency order:

| file | what it is |
| --- | --- |
| `Service` | base class: FreePBX handle, PDO, manager, the four table names, `rowCount()`, prefixed log helpers |
| `Repo` | base class for the two file directories |
| `Logs`, `Enabled` | traits: writing to the FreePBX log; the on/off switch two tables share |
| `Mac` | a MAC as written, and as found in a filename (static) |
| `Freepbx` | the only file that asks FreePBX about a device |
| `Template` | `{{name}}` and the flat map behind it |
| `Tokens` | hashing a client's token, checking one |
| `Schema` | the columns added after a table first existed |
| `FileRepo` | `ASTSPOOLDIR/repo`, one file per resource id |
| `LogRepo` | `ASTLOGDIR/provisioner/<client id>/`, what a phone sent back |
| `Clients`, `Profiles`, `Resources` | one per table |
| `Matcher` | a filename is a MAC and a name, read both ways |
| `Previews` | which filename does this phone ask this file by |
| `ProvisioningLog` | one row per request the endpoint answered |
| `Counts` | how many rows a tab is labelled with |
| `Endpoint` | answering a provisioning request, and ending it |
| `Pages` | which URL is which page |
| `Installer` | install, uninstall, the web-root symlink, registering the settings |
| `Settings` | the module's PBX-wide settings: one definition each, registered, drawn on the Settings tab, saved |
| `AsteriskConfig` | one Asterisk config file, edited without disturbing what others wrote |
| `EndpointSettings` | the From Domain chain, and `pjsip.endpoint_custom_post.conf` |
| `NumberAllocator` | which numbers are free, and the next `999…` one |
| `ExtensionManager`, `UsermanManager`, `VoicemailManager`, `UcpAssignments`, `CdrHistory` | one each of what a number is made of |
| `ExtensionRenumberer` | moving a user to another number, in order |
| `Users` | saving, deleting and listing a user |
| `Fail2ban` | the only file that asks fail2ban, through the sudo helper |
| `Bans` | listing, adding and lifting a ban |

The last seven came from `oryk_connect` 1.3.2, which this module replaces for
Extension/User devices.

`install()` symlinks `engine/` to `<AMPWEBROOT>/provisioner`, which is the short
URL phones are given. **Nothing about that link is allowed to fail the install**
-- a module that could not write to the web root is a working module minus a
friendly URL, and the endpoint stays reachable at its real path.

## Schema

Four tables. Every `Schema` step is additive and asks `information_schema`
rather than a dbversion: "is the column there?" answers the same whether the
module arrived by upgrade, reinstall or a restore of an older backup, and a
failed DDL statement is not something a PDO exception cleanly distinguishes from
a dead connection on every MySQL build this runs on.

**`oryk_provisioner_clients`**

| column | why it is the way it is |
| --- | --- |
| `mac` | nullable, so a client can exist before anybody read the label off the handset. **NULL and not `''`**: the unique key counts NULLs as distinct and empty strings as equal, so stored as `''` it would admit one MAC-less client and refuse every one after it |
| `device_id` | VARCHAR -- FreePBX `devices.id` is a string column, so no cast on every join. Deliberately keeps the name: it holds a FreePBX device id |
| `token` | a `password_hash()`. No plaintext column, no index: verified against, never looked up by |
| `enabled` | NOT NULL DEFAULT 1. A three-state column would have a value meaning "nobody has said" |
| `public_ip`, `private_ip` | written down by whoever set the phone up, never discovered. `private_ip` becomes an `href` on the Clients list, which is why both are `filter_var`-validated on the way in |
| `last_seen` | nullable, no default. On the client rather than derived from the log, because the log is prunable and when a phone last checked in must survive its requests being thrown away |

**`oryk_provisioner_profiles`** -- `name` (unique), `enabled`. A profile has no
template of its own; the main config is a resource named `.cfg` like any other
file (1.0.7).

**`oryk_provisioner_resources`** -- `profile_id`, `name`, `type`, `template`,
`file_size`, `file_uploaded_at`.

- `name` unique per profile, not globally: two profiles both serving a
  `{{device.mac}}-phone.cfg` is the normal case. 180 characters because it is
  half of a composite index, which has to stay inside the 767 bytes an older
  MySQL allows.
- No separate index on `profile_id` -- it is the left of the unique one. There
  *is* a separate index on `name` alone, for the by-name lookup a request with
  no client behind it makes.
- **`type` is the only thing that says what a resource is.** VARCHAR(16) rather
  than an ENUM, so a fourth kind is a line in `Resources::TYPES` rather than an
  ALTER. Never infer a kind from `file_size` or from the template column -- that
  inference is what 1.0.14 removed, and it meant a resource could not be a file
  until a file was already on it and could not be a log at all.
- `file_size` is a fact about the upload. Null on a resource declared a file
  that nobody has uploaded to: an unfinished resource, and answered as one.

**`oryk_provisioner_logs`** -- one row per request the endpoint answered.

- No `client_id` and no foreign key: a log row outlives the client it was about
  and predates the one it was not, so which client a MAC belongs to is a
  question asked when the log is read.
- `mac` is 64 rather than 12, because it also holds what was asked with when
  that was not a MAC at all.
- **Metadata only.** The rendered body carries `device.secret` whenever a
  template asks for it. Nor is which resource answered stored -- the filename as
  the phone spelled it is the fact of the request.

### Migrations deliberately not written

Renamed tables and dropped columns (1.0.7's profile `template`, 1.0.8's
`devices` -> `clients`) have no migration. Stage 1 has no installed base, a
migration written for nobody is a migration nobody has run, and a silent one
would leave every existing profile with a resource nobody wrote. The changelog
says what to do by hand.

## Settings

A PBX-wide setting is a FreePBX setting -- a row in `freepbx_settings`, filed
under *Advanced Settings → Oryk Provisioner* -- and the Settings tab
(`?tab=settings`) is a second view of the same rows, so neither place can be
out of date with the other. FreePBX removes them when the module is
uninstalled.

**A new setting is one entry in `Settings::definitions()`**: keyword, name,
description, type (`text`, `int`, `bool`, `select`) and what bounds it. From
that entry `install()` registers it, the tab draws and posts it
(`views/partials/settings.php` names no setting), and `saveSettings` validates
it. Its consumer reads it with `Settings::get()` or `\FreePBX::Config()->get()`.
Nothing else is written -- no view, no AJAX command, no schema step -- but it
appears only after the module is installed or upgraded, since that is what
registers it.

- **Registering never resets a value.** Every install defines each setting
  again with its stored value passed back in, which is also how
  `ORYK_FROM_DOMAIN` was taken over from `oryk_connect` without being blanked.
- **The keyword is never taken from a request.** A posted keyword is looked up
  in the definitions; one that is not there is ignored.
- **A save is all or nothing.** Every posted value is validated before any is
  written, against the same rule FreePBX applies in Advanced Settings.
- The tab is the one list tab with fields, so it is the one list tab with a
  Save in the action bar, and Save reloads the tab.
- A value is read when it is used. A setting that shapes something already
  written -- the From Domain on an endpoint -- reaches it on that thing's next
  save, not when the setting changes.

## Users

A user is a row in none of this module's tables. It is a `pjsip` device whose
id equals its `user` -- `Users::SHAPE` -- so the device id, the extension and
the User Manager username are one number. Extensions made in FreePBX have the
same shape and are listed too. Everything about one lives elsewhere:

| | where | written by |
| --- | --- | --- |
| device, SIP settings | `devices`, `sip` (incl. `email`, `from_domain`, `kind`) | Core `addDevice()` |
| extension | `users`, astdb `AMPUSER/` | `ExtensionManager` |
| User Manager account | `userman_users` | `UsermanManager` |
| mailbox | `voicemail.conf`, the spool | `VoicemailManager` |
| UCP access | `userman_*_settings`, `webrtc_clients` | `UcpAssignments` |
| call history | `asteriskcdrdb` | `CdrHistory` |
| From Domain | `pjsip.endpoint_custom_post.conf` | `EndpointSettings` |
| provisioner clients | `oryk_provisioner_clients.device_id` | `Clients` |

**A save** (`Users::store()`) deletes the device and adds it again -- Core has
no edit -- so it starts from the device's stored settings, not driver defaults:
only keywords the driver names, plus `Users::CUSTOM`, are carried, so a setting
made in FreePBX survives and nothing stray is written to `sip`. On every save
`media_encryption=sdes` and `media_encryption_optimistic=yes` are forced, the
account, extension name, User Manager name and both emails are synced, EPM is
run, the endpoint file is written and a full reload runs -- so the AJAX call
takes as long as Apply Config. A blank number keeps the user's own (a new one
takes the next free `999…`); a blank secret keeps the stored one. A number held
by any device, extension or account is refused before anything is written.

**A custom username** -- given by open provisioning, as the extension form's
"Use Custom Username" gives one -- leaves the account no longer named after the
extension, so `UsermanManager::setLogin()` marks it with the User Manager module
setting `oryk_provisioner/owned` first. `ownedAccount()` counts either as this
module's: it is synced, moved with a renumber (keeping its username) and deleted
with the user.

**A renumber** is a save whose number changed. The order is the point
(`ExtensionRenumberer`): the new extension exists before the old is given up,
so a failure leaves the user where it was; the mailbox moves before the old
extension is deleted, and that delete is in edit mode when the mailbox did not
move, so Voicemail cannot delete a box still in use; User Manager moves after
the old extension is gone; handsets are repointed; **provisioner clients are
repointed** (`Clients::repointDevice()`); UCP access moves before the history
it opens; the history is rewritten in place (`src`, `dst`, `cnum`, `clid`, both
channel names; recording file names are left, since they must match the file).

**A delete** removes the device and its endpoint section and **releases** every
client pointing at it (`device_id` NULL; MAC, profile and token kept). Once no
other device points at the extension, the extension, the account this module
owns, its UCP assignments and its **call history and recordings** go too, only
after the extension itself is gone. History is found by `src` or `dst` matched
exactly, then every row sharing a `uniqueid` or `linkedid` with those is
deleted from `cdr`, `transient_cdr`, `replicate_cdr` and `cel`; a recording is
unlinked only when no surviving record names it. A queue- or ring-group-
answered call carries the group in `dst` and is not matched. There is no undo,
which is why Delete says so before it asks.

**The From Domain** is three questions, first answer wins: the device's own
`from_domain`; `ORYK_FROM_DOMAIN`, the [setting](#settings) in *Advanced Settings → Oryk
Provisioner* and on the Settings tab;
the PBX hostname, only when it is a domain name (not bare, not `.local`, not
`localhost`). Nothing resolved takes the setting off the endpoint rather than
leaving the old one. `ORYK_FROM_DOMAIN` is the keyword `oryk_connect`
registered: `install()` re-registers it as this module's and passes the stored
value back in, so taking it over never blanks it. A changed PBX value reaches
an endpoint on that user's next save.

**`pjsip.endpoint_custom_post.conf` is shared ground.** FreePBX never rewrites
it and any module may write to it. A `[<id>](+)` section adds to the endpoint
FreePBX generated. `AsteriskConfig` edits it in place -- a setting rewritten
where it stands, a missing one added inside its section, every other line and
section byte for byte, a no-op save writing nothing -- under a lock, via a
temporary file renamed over it with the old owner and mode. **The lock file is
named `oryk-connect-<md5>.lock`** so this module and `oryk_connect` take the
same lock while both are installed. Do not rename it until Connect is gone
everywhere.

`install()` adds the `devices.id` (unique), `devices.user` and
`userman_users.email(191)` keys `oryk_connect` added, under the same names.
`userman`, `voicemail` and `cdr` are soft dependencies: each subsystem asks
`moduleActive()` and declines rather than throwing.

## Bans

A ban is a row in none of this module's tables: it is one (jail, address) pair
in fail2ban, asked for every time it is shown, and named `jail/ip` wherever one
string has to name it (a jail cannot hold a slash; an address does not). Its
page is `?jail=<jail>&ban=<ip>`, the way a resource hangs off its profile. A
ban cannot be edited, so an existing one has Unban and Close and no Save.

**`ORYK_FAIL2BAN`** (a [setting](#settings), on by default) switches the whole
thing. `Fail2ban::enabled()` is the one place it is read: off, every call
answers not-ok without running sudo and `status()` is `disabled`, and `Pages`,
`Navigator` and `views/admin.php` leave the tab and the section out. It does
not touch the helper or the sudo rule; `--remove` does. `Settings::get()`
answers a setting's default until install has registered it, so new files on a
box not yet upgraded do not read it as off.

**The privilege boundary.** fail2ban's socket answers root only, and the GUI
runs as the web user. `Fail2ban` -- the only file that asks -- runs
`sudo -n /usr/local/sbin/oryk-fail2ban <verb> …` with an argument array, never
a shell string. The helper is Python because fail2ban already needs it, and it
is the boundary: it accepts `check`, `jails`, `count`, `list [jail]`,
`ban <jail> <ip>` and `unban <jail> <ip>`, re-checks every argument (a jail
fail2ban has; one address, no range, no zone id), runs `fail2ban-client` with a
fixed argument list, and answers one line of JSON. Exit 64 is a refused
argument, 69 fail2ban down.

- **The helper sudo runs is a root-owned copy, never the module's file.** The
  module directory is writable by the web user, so a sudo rule pointing into it
  would be root for anyone who can write there.
- **One way to install it:** `bin/oryk-fail2ban-setup`, run as root by hand, or
  by `install()` when that runs as root. It writes the sudoers file under a
  dotted name, which `includedir` skips, and renames it into place only after
  `visudo -c`. It checks the whole sudo configuration first, so a problem
  already there is reported as that; a wrong mode or owner on another file in
  `/etc/sudoers.d` -- which sudo tolerates and `visudo -c` does not -- it fixes
  and says so, and anything else it prints and stops. Nothing about it fails
  an install; `uninstall()` as root runs `--remove`.
- **`HELPER_VERSION` is how a stale copy is found.** The tab and the setup
  script read the same line from both copies; bump it whenever the helper
  changes.
- **The tab says what is wrong, in order:** `missing` (no helper), `sudo` (sudo
  refuses -- no JSON came back), `stale`, `fail2ban` (the helper answers,
  fail2ban does not), `ok`. Anything but `ok` draws the setup command, built from
  this module's real path, in place of the table; no ban page opens.
- **Times are fail2ban's local time**, turned into epochs by the helper, and
  every age is subtracted on the helper's `now` -- the same reasoning as Last
  Seen: the browser's clock is not the PBX's.
- **Each helper call costs a sudo and two Python start-ups.** `Fail2ban` asks
  each read once per request, and `Counts` asks for the ban count only for the
  unnarrowed scope -- the module page, the one strip with a Bans tab.
- **A ban refuses** the requester's own address, loopback and unspecified
  addresses, and the PBX's own; a client's public address is warned about in
  the browser, not refused. **Unbanning** an address no longer banned succeeds:
  the request says the state wanted, as the enabled switch does.

## Conventions that hold everywhere

- **Everything the module edits is a page**, told apart by which key the URL
  carries: `?client=`, `?profile=`, `?profile=<id>&resource=`, `?user=`,
  `?jail=<jail>&ban=`. The
  key present and empty is the "new one" editor. `Pages::doConfigPageInit()`
  bounces an id that names no row *before any markup* -- a redirect out of
  `showPage()` would be too late to set a header. A user's key is its
  extension, so a renumbering save lands on a new address.
- **A tab is a link.** `?tab=` is read server-side, only the pane asked for is
  rendered, and `views/partials/tabs.php` draws the rest as links. A tab with
  nothing behind it is not drawn. Nothing about tabs is scripted. The
  consequence: Save is drawn only on the editor's own tab, because on any other
  tab the fields it posts are not on the page.
- **Save stays on the row it wrote.** An editor's Save lands on that row's own
  page: the address it was already at when the row existed -- so the page
  reloads with what was written on it -- and the new row's first address when
  it did not, which is the load that brings the rest of the editor on to the
  page (a resource's file box and template, a profile's Resources tab). The URL
  is the row and nothing else: no key says a save has just happened. Only Close
  and a finished Delete go back to a list.
- **No view contains a `<form>`.** The module page renders inside the FreePBX
  page form and a nested form is dropped by the browser. Fields are read by id
  and posted with an explicit `$.ajax({type: 'POST'})` to `ajax.php`.
- **Action bar buttons are `oryksave` / `orykdelete` / `orykclose`**, not the
  `submit`/`delete` core wires to a `form.fpbx-submit` none of these pages has.
- **A field's help is FreePBX's (?) icon.** FreePBX hides every
  `.fpbx-help-block` until a `<i class="fa fa-question-circle fpbx-help-icon"
  data-for="<id>">` beside the label is hovered, and then shows the one element
  with id `<id>-help` -- so help without that pair is never seen. A field with
  several paragraphs puts them in `.oryk-help-part` spans inside one block.
- **Tab badges come from `Counts`, never from the table they label** -- a table
  with something in its search box answers with the total of what matched.
- **A table or column name written into SQL is interpolated, so it may never
  come from a request.** Sort columns are whitelisted and mapped; everything
  else is bound.
- **A MAC is twelve lowercase hex characters, separators stripped**
  (`Mac::normalize()`). `Mac::stored()` is the log's variant, which keeps what
  was sent when it was not a MAC.
- **The enabled switch sends state, not a toggle.** The same request twice
  leaves the row where the first put it, and two tabs open on a list cannot
  flip past each other.
- **Only a 200 counts as a sighting** (`Clients::touchClient()`), and that write
  assigns `updated_at = updated_at` on purpose -- the column is
  ON UPDATE CURRENT_TIMESTAMP, so without it every phone that booted would read
  as a client somebody had just edited.
- A new AJAX command must be named in **both** `ajaxRequest()` and
  `ajaxHandler()`.
- PHP and views are indented with **tabs**. Operator-facing strings go through
  `_()`.

## What a phone actually sends

Observed from a Polycom VVX 500, which is the fleet this was built against.
Useful when reasoning about matching, because the vendor's naming is the whole
problem the `Matcher` exists to solve.

| Method | Request URI | User-Agent |
| --- | --- | --- |
| PUT | `/0004f282e824-boot.log` | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.47435 Type/Updater` |
| GET | `/0004f282e824.cfg` | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
| GET | `/3111-44500-001.3111-44500-001.sip.ld` | `... Type/Application` |
| GET | `/0004f282e824-phone.cfg` | `... Type/Application` |
| GET | `/0004f282e824-web.cfg` | `... Type/Application` |
| GET | `/000000000000-license.cfg` | `... Type/Application` |
| GET | `/0004f282e824-license.cfg` | `... Type/Application` |
| GET | `/0004f282e824-calls.xml` | `... Type/Application` |
| GET | `/0004f282e824-directory.xml` | `... Type/Application` |
| GET | `/000000000000-directory.xml` | `... Type/Application` |
| HEAD | `/0004f282e824-app.log` | `... Type/Application` |

AudioCodes puts the MAC in the User-Agent instead:
`AUDC-IPPhone/2.0.0_build_15 (420HD; 00908F3BBCBA)`. Grandstream puts it in the
middle of the name: `cfg0004f282e824.xml`.

## Why the endpoint is not `config.php`

Tried first and reverted. FreePBX 16/17's `config.php` does, near the end of its
menu/auth block, `if (empty($_SESSION['AMP_user'])) { $display = 'noauth'; }` --
*unconditionally*. For a session-less request `$display` is rewritten to the
login page before `doConfigPageInits()` runs, so a module's
`doConfigPageInit()` never executes. A menu item's `requires_auth="false"` only
governs menu visibility and the permission gate earlier in that block; it does
not grant an anonymous request access to a display page. A public route has to
bootstrap FreePBX on its own.

## Not built yet

- A token that *identifies* a client, as `/provisioner/{token}/{file}` would.
  Tokens are opt-in per client and nothing makes you set one.
- Uniform refusals. A failure still says which kind of failure it was, so a
  caller probing MACs can tell a known one from an unknown one. Closing that is
  the token scheme's job and is a change to all the messages at once.
- No rate limiting or lockout on token verification.
- Copying resources between profiles, or a seeded starting resource. A profile
  is set up one file at a time from empty.
- No `fwconsole` command. Backup/restore hooks are stubs.
- Only Connect's Extension/User kind was ported. Handsets are clients here;
  Connect's softphone and RTSP kinds have no equivalent, and an RTSP device
  needs Connect's driver installed.
- Renumbering does not check ring groups, queues or other destinations for the
  old number. A PBX-wide From Domain change is not pushed to existing endpoints.
- GraphQL API, per-client parameter overrides, a bundled vendor template
  library, template filters/sections/escaping.
