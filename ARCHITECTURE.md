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

A **service** is a name, linked to any number of services it is under and any
number under it, and assigned to users. The endpoint knows nothing of a
service, but a template can ask what a user holds, and every change to what a
user holds is a **job**: one step per service gained or lost, run at once,
calling every module hooked to it -- this one included, whose own jobs are
classes in `src/Jobs/`.
See [Schema](#schema) and [Jobs](#jobs).

A **user** is the other half of the module: a pjsip device that is its own
extension, managed from the Users tab, and stored in none of this module's
tables. A client's device is usually one. See [Users](#users). A **ban** is a
rule naming any of an address, a MAC, a user, a client and a profile, and saying what the
endpoint does with a request that matches all it names: refuse it for a while,
refuse it for good, or answer it in spite of a less specific ban. See
[Bans](#bans).

## Request flow

```
phone
  -> engine/.htaccess          rewrites anything under engine/ to provisioner.php
  -> engine/provisioner.php    who is asking (MAC) + what they asked for (last
                               path segment). Bootstraps FreePBX directly:
                               freepbx_auth=false, restrict_mods=true.
                               ORYK_PROVISIONING=DISABLED: 503, unlogged
  -> Oryk_provisioner::serve() / ::receive()      thin passthrough
     or ::openProvision()                         MAC 000000000000, OPEN only
  -> Endpoint::banned()                           Bans::decision(): 403 before
                                                  anything is looked up or made;
                                                  an allow lifts sign-up limits
  -> Endpoint::openProvision()                    finds or makes the client its
                                                  credentials log in as, then
                                                  serve()s / receive()s as it
  -> Endpoint::resolveRequest()                   decides
  -> Endpoint::answer()                           transcodes by Accept, logs, sets
                                                  status, sends, exits
```

`resolveRequest()` is the whole of the decision about *what* answers and is
deliberately callable without the exit -- previews, console commands and tests
use it. Bans are asked before it, by `serve()` and `receive()`, so a preview is
never refused for the address an admin is browsing from. **Its order is the
security of the thing:**

1. disabled client -> 403
2. a client with no profile: the profile named after the vendor its
   User-Agent names (`Vendor`), or, with no vendor or no profile by that
   name, the one named `Default` (`Profiles::profileFor()`), matched without
   regard to case, for this request only -- nothing is stored
3. disabled profile -> 403
4. match a resource of the client's profile by name
5. no client, or still no profile: the resources declared `file`, matched by name
   exactly (this is how firmware is fetched by a phone that sends no MAC)
6. token, when the client has one -> 401
7. the resource's `type` decides what the answer is

The token is asked for **after** a match and **before** the type, so a 401
cannot tell an unauthenticated caller which files exist. Step 5 is files only:
a template with no client behind it has no values to render against, so the
phone would get a config that parses and is wrong.

**Known exposure, inherent to MAC-based provisioning.** Anyone who reaches the
URL and knows a MAC gets that client's config, `device.secret` included, unless
the client has a token. An uploaded file can be fetched by anyone who knows what
it is called. Restrict the URL at the network layer until the token scheme is
mandatory.

### Open provisioning

With `ORYK_PROVISIONING` OPEN, a request for `Mac::OPEN` (`000000000000`) is
answered by its Basic credentials instead of a client row
(`Endpoint::openClient()`), in this order:

1. no credentials -> 401, which is the challenge that makes a phone send them
2. `Users::findLogin()`: a User Manager login answers with the account's
   default extension, and its client (step 4) -- no lock, no limits, nothing
   made; no User Manager, or a login whose account names no number -> 409
   A login whose extension or device has been deleted is **rebuilt** first
   (`Users::rebuild()`): the extension and a device on the number the account
   still names, made as a sign-up makes them -- in the lobby, whatever the user
   was -- bridged, and written to the security log.
3. otherwise a **sign-up**, all of it under `Users::LOCK` (one `withLock()`
   in `openClient()` around `Users::signUp()`, the client and the bridge; the
   lock is re-entrant, so `store()` inside it takes nothing more):
   1. a username held under another password -> 401, the one line written to
      FreePBX's security log as a GUI login failure, so the jail that watches
      it bans the address
   2. `Users::signupUsername()` (`[A-Za-z0-9._@-]`, 1-64, not only digits --
      a number would be taken for that extension's own account by
      `UsermanManager::findByExtension()` -- and not an IP address), else 400
   3. `Users::reservedUsername()`: `Users::RESERVED` and `RESERVED_PREFIX`,
      on the whole name and the part before `@`, and any address at
      `ORYK_HOSTNAME`'s or `ORYK_FROM_DOMAIN`'s domain -> 400. A list in the
      code, deliberately not a setting. Asked even when an allow decided
   4. `Endpoint::admit()`, unless an **allow** decided the request: the
      address's sign-ups (`Clients::signupKey()`: an IPv4 address, or an IPv6
      /64) in the last minute and day, then the PBX's in the last day, against
      `ORYK_OPEN_PER_MINUTE`, `_PER_DAY`, `_PER_DAY_TOTAL` (0 is none) -> 429
      with `Retry-After`. Counted from client rows, which is why the check and
      the client's write are under one lock -- outside it, fifty parallel
      requests all count zero
   5. `Users::store()` with the lobby: `context` `ORYK_OPEN_CONTEXT`,
      `emergency_cid` `ORYK_OPEN_EMERGENCY_CID`, `max_contacts=1`,
      `remove_existing=yes`; no email, even from a username that is one, so
      User Manager's welcome email never goes to an unconfirmed address. The
      account gets that username and password (see [Users](#users)), then
      `UsermanManager::denyUcp()`: a per-user `ucp|Global allowLogin` of false
   6. the client (step 4) is made `created`, with `signup_ip`, and its
      endpoint, auth and AOR go into the [Realtime bridge](#the-realtime-bridge)
4. `Clients::findOrCreateForDevice()`: the user's client on an internal MAC,
   made when there is none, with no profile and the credentials as its
   token; a found one is given the credentials again when its token no longer
   verifies, so a password changed in UCP does not lock the phone out. A client
   on a real phone's MAC is never used.

Every sign-up and every refusal at 3.3-3.4 is a `SecurityLog` line, and none
of them says `Authentication failure`, so a phone retrying after a 429 does
not get its site banned. `SignupRefused` carries a refusal's reason, status
and retry from where it is decided to where it is logged.

The request is then served or received as that client, `000000000000` in the
filename swapped for its MAC, so the ordinary order above -- token included --
decides the answer -- including the vendor's profile or `Default`, since the
client has none of its own. **Nothing reloads**: the sign-up raises Apply Config like any
save.

**The lobby** (`LobbyContext`, a dialplan hook at priority 900, after Core):
with `ORYK_OPEN_CONTEXT` `lobby`, `[lobby]` counts the calling endpoint's
calls in `GROUP(oryk-lobby)` against `ORYK_OPEN_CALLS` and goes on to
`[lobby-dial]`, which includes `ext-local`, `ext-meetme`, `app-vmmain`,
`app-dialvm`, the `outrt-<id>` of each route flagged emergency, and last
`[lobby-deny]` ("no service") -- searched in order, so deny only catches what
nothing allowed matched. `lobby` and `lobby-dial` also get an `i` ("no
service"): FreePBX writes a context only when it has an extension of its own,
and `lobby-dial` would otherwise be all includes and never written. No other route, no ring group, queue or paging, no
other feature code. Counted by `CHANNEL(endpoint)`, which a phone cannot set as
it can its caller id. Two ways out are closed besides the dial plan:

- **a forward**: a callee's 302 is followed by Dial in `FORWARD_CONTEXT`,
  which FreePBX sets to `from-internal`; `__FORWARD_CONTEXT` is spliced onto
  every extension in `ORYK_OPEN_CONTEXT` at `ext-local` priority 1, whatever
  the context is called;
- **a transfer**: `allow_transfer=no` on every endpoint in it, written by
  `Users::endpointExtras()` to `pjsip.endpoint_custom_post.conf` and by the
  bridge.

**The module never changes a context.** `store()` takes `context` on a new
user only, and `saveUser()` passes only the editor's fields, so a request
cannot set one. A user leaves the lobby when its context is changed in
Extensions: the call limit and forward guard follow the context on the next
apply. Two things the sign-up wrote do not follow by themselves -- the
transfer guard, which the user's next save here takes off
(`endpointExtras()`), and the per-user UCP login `denyUcp()` refused, which is
User Manager's to switch back. **Expired** (`ORYK_OPEN_EXPIRE_DAYS`): a lobby user whose
newest client was last seen, or whose sign-up client was made, more than N days
ago -- `Users::expired()` in PHP and `expiredExpr()` in SQL, which must agree:
the list shows what the SQL matches, and `deleteExpired()` deletes only what
the PHP still says yes to.

**Unverified**: the forward guard, the included context names and UCP's
per-user override are written from FreePBX's and Asterisk's documentation and
are on the open-signup plan's checklist for a test PBX.

## Matching a filename

`Matcher` reads a filename both ways, and that is the module's central trick.

| resource name | phone asks for | how it matches |
| --- | --- | --- |
| `phone.cfg` | `0004f282e824-phone.cfg` | MAC taken off the front (`resourceSuffix()`), tail compared |
| `.cfg` | `0004f282e824.cfg` | same; the dot of an extension stays with the name |
| `{{device.mac}}-phone.cfg` | `0004f282e824-phone.cfg` | name rendered against this client, compared whole |
| `000000000000-directory.xml` | the same | matched literally -- that MAC is not this client's |

A name written out in full wins over a bare tail. `resourceRequest()` is the
same thinking backwards and is what every Open link is drawn from -- a name
carrying *another* device's MAC gets no link (and no Render or Download), because the endpoint reads the MAC
out of the path in preference to `?mac=`, so the link would answer for the wrong
phone.

## Asking for another format

`Endpoint::answer()` first runs the result through `transcoded()`: a template or
uploaded file goes out as stored unless the `Accept` header names a format
(`Transcoder::target()`) and nothing else. Any wildcard means no transcoding,
so a phone is never rewritten by accident. Already in that format: sent byte for
byte, and a file keeps its ETag/304. Otherwise it is rewritten and sent as text;
binary, over `Transcoder::MAX_BYTES`, or undetectable is a 406. Logs are never
transcoded.

**Nothing the endpoint sends runs as a page.** `engine/provisioner.php` sends
`X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox;
default-src 'none'` on every answer, and `Endpoint::viewResource()` (Render) the
same pair on a text/plain body: `/provisioner/` shares the GUI's origin, and a
body -- an `.xml` template, a log a phone PUT -- must not be able to script it.
Phones ignore both headers.

**A stored log is at most `LogRepo::MAX_KEPT` bytes**, its newest, cut to start on
a whole line; a body declared or found to be over `LogRepo::MAX_BODY` is a 413 and
nothing is written. It is written to `<name>.part` and renamed.

Every format is read into one tree (ordered arrays of name => string, tree or
list) and written out of it:

| format | read | written |
| --- | --- | --- |
| JSON | decoded, scalars to strings | pretty-printed object |
| XML | `{root: node}`; attributes `@name`, children by tag (repeats a list), text `#text` or the node itself | the inverse; more than one top key goes in `<config>`; a key that is no XML name is `<item key="...">` |
| plain | flat `key=value`, keys kept whole, `[section]` prefixes, comments dropped | flattened, path joined with `.`, `@`/`#text` dropped |

Detection runs on the rendered text, never the raw template. A DOCTYPE is
refused. Plain is a reading, not a copy: XML -> plain -> XML does not come back.

## Files

```
Oryk_provisioner.class.php   BMO contract, src/ autoloader, AJAX dispatch table.
                             A thin adapter since 1.0.13 -- no behaviour.
page.oryk_provisioner.php    one line into showPage()
engine/provisioner.php       the anonymous endpoint a phone reaches
engine/.htaccess             rewrites everything under engine/ to provisioner.php
bin/                         the fail2ban helper, its setup script, the minute sync -- see Syncing with fail2ban;
                             the open-provisioning sweep -- see The Realtime bridge; the job worker -- see Jobs
src/                         53 files and Jobs/ (5), namespace FreePBX\Modules\Oryk_Provisioner
library/                     the profiles the module ships, one directory each -- see The library
tests/                       smoke.php and the stubs it runs against
views/                       one view per page, plus views/partials/
```

`src/`, roughly in dependency order:

| file | what it is |
| --- | --- |
| `Service` | base class: FreePBX handle, PDO, manager, the table names, `rowCount()`, prefixed log helpers |
| `Repo` | base class for the two file directories |
| `Logs`, `Enabled` | traits: writing to the FreePBX log; the on/off switch two tables share |
| `Mac` | a MAC as written, and as found in a filename (static) |
| `Vendor` | the vendor a User-Agent names, which a client with no profile is served the profile of, before `Default` (static) |
| `Freepbx` | the only file that asks FreePBX about a device |
| `Template` | `{{name}}` and the flat map behind it |
| `Tokens` | hashing a client's token, checking one |
| `Schema` | the columns added after a table first existed |
| `FileRepo` | `ASTSPOOLDIR/repo`, one file per resource id |
| `LogRepo` | `ASTLOGDIR/provisioner/<client id>/`, what a phone sent back |
| `Clients`, `Profiles`, `Resources`, `Services` | one per table |
| `Library` | the profiles the module ships, and making one of the operator's from one |
| `Matcher` | a filename is a MAC and a name, read both ways |
| `Previews` | which filename does this phone ask this file by |
| `ProvisioningLog` | one row per request the endpoint answered |
| `Endpoint` | answering a provisioning request, and ending it |
| `Pages` | which URL is which page |
| `Installer` | install, uninstall, the web-root symlink, registering the settings |
| `Settings` | the module's PBX-wide settings: one definition each, registered, drawn on the Settings tab, saved |
| `Notices` | the notices over every module page: one definition each, which of them a page shows, what was dismissed |
| `AsteriskConfig` | one Asterisk config file, edited without disturbing what others wrote |
| `EndpointSettings` | the From Domain chain, and `pjsip.endpoint_custom_post.conf` |
| `NumberAllocator` | which numbers are free, and the next `999…` one |
| `ExtensionManager`, `UsermanManager`, `VoicemailManager`, `UcpAssignments`, `CdrHistory` | one each of what a number is made of |
| `ExtensionRenumberer` | moving a user to another number, in order |
| `Users` | saving, deleting and listing a user |
| `Bans` | the bans table, and the question the endpoint asks it before answering |
| `Fail2ban` | the only file that asks fail2ban, through the sudo helper |
| `BanSync` | IP bans and fail2ban in step: the minute job, and a save carried over at once |
| `SecurityLog`, `SignupRefused` | lines in FreePBX's security log; a refused sign-up, on its way there (static; exception) |
| `RealtimeBridge` | a sign-up's endpoint, auth and AOR, live before Apply Config |
| `DashboardNotices` | the module's dashboard notices, raised and cleared on a change only |
| `SignupSweep` | the minute job behind open provisioning: out of the bridge once applied; the notices |
| `LobbyContext` | the lobby's dialplan, on Apply Config |
| `Jobs` | the jobs and their steps: written, listed, and what the worker asks while it runs one |
| `ServiceEngine`, `HandlerFailed` | what a change means for a user (`changes()`, static), and the worker; a handler that threw (exception) |
| `Reactions` | the module's own jobs: finds the classes in `src/Jobs/`, and runs the one a step's service names |
| `Jobs\Job`, `Jobs\*` | one class per job of the module's own, each naming the services it is run for |

`AsteriskConfig` through `Users` came from `oryk_connect` 1.3.2, which this module replaces for
Extension/User devices.

`install()` symlinks `engine/` to `<AMPWEBROOT>/provisioner`, which is the short
URL phones are given. **Nothing about that link is allowed to fail the install**
-- a module that could not write to the web root is a working module minus a
friendly URL, and the endpoint stays reachable at its real path.

## The library

`library/<vendor>/<set>/` is one **entry**: a `manifest.json` and the files it
names. `Library` reads them; nothing is seeded into a table, and the endpoint
never serves from here.

```json
{
	"name": "Polycom VVX",
	"vendor": "Polycom",
	"version": 1,
	"skus": ["VVX500"],
	"resources": [
		{"name": "{{device.mac}}.cfg", "type": "template", "source": "mac.cfg"},
		{"name": "app.log", "type": "log"},
		{"name": "sip.ld", "type": "file", "note": "what to upload"}
	]
}
```

- **A profile made from an entry is a copy.** `Library::saveProfile()` writes
  the profile, then each resource through `Resources::saveResource()`, then
  records the entry's id and version on the profile. From there it is the
  operator's: an upgrade that changes the entry changes no profile. This is the
  opposite of a module-owned service, which is seeded and refused every edit --
  nobody edits a service, and everybody edits a phone's configuration.
- **All or nothing, twice.** A manifest with one unusable resource is no entry
  (`entry()` answers null), and a profile whose resources could not all be made
  is deleted again.
- **A resource's `name` is not a filename on disk.** `{{device.mac}}.cfg` is
  kept as `mac.cfg`; `source` says which, is one path segment
  (`SOURCE_PATTERN`), and is the only thing read.
- **What each type carries.** A template has a `source`. A log has none: it is
  only the declaration that a phone may PUT that name. A file may have one --
  its bytes are stored as an upload's would be -- or none, and is then made with
  nothing uploaded, which the [schema](#schema) already calls an unfinished
  resource. That is how firmware is declared without being shipped; its `note`
  says what to upload, and the resource's page shows it for as long as the
  resource keeps the name the manifest gave it.
- **An entry's id is the one value a request turns into a path**, and only
  after `ID_PATTERN`.
- `skus` are the models the entry is known to work on. Nothing matches on them
  yet.
- An entry nobody has run on a phone says so in its `name`: it ends
  `(untested)`, and the name is the whole of the marking.

## Schema

Ten tables, and the bridge's three (below). Every `Schema` step but one (the services table's re-keying) is additive and asks `information_schema`
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
| `state` | `provisioned` (the default, and every row older than 1.2.5) or `created`: a sign-up whose extension Apply Config has not yet been seen to write. Read by the [sweep](#the-realtime-bridge) |
| `signup_ip` | the address open provisioning made the client for, as `Clients::signupKey()` stores it -- an IPv6 address as its /64, since that is what the limits count by. NULL on every other client. Keyed with `created_at`, which is what the limits count. Deleting a user deletes its clients and so lowers the count; only an admin can |

**`oryk_provisioner_profiles`** -- `name` (unique), `enabled`, `library`,
`library_version`. A profile has no template of its own; the main config is a
resource named `.cfg` like any other file (1.0.7). `library` and
`library_version` are the [library](#the-library) entry a profile was copied
from and its version then, NULL on one written by hand. A record, not a link:
nothing reads them to decide what is served. They are asked for on their own
(`Profiles::library()`), so a table that has not been given them yet still has
profiles that open.

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

- No `client_id` and no foreign key: a log row predates the client it is about,
  so which client a MAC belongs to is a question asked when the log is read.
  Deleting a client deletes the rows with its MAC (`Clients::deleteClient()`).
- `mac` is 64 rather than 12, because it also holds what was asked with when
  that was not a MAC at all.
- **Metadata only.** The rendered body carries `device.secret` whenever a
  template asks for it. Nor is which resource answered stored -- the filename as
  the phone spelled it is the fact of the request.

**`oryk_provisioner_bans`** -- `client_id`, `extension`, `mac`, `profile_id`,
`ip`, `state`, `expires_at`, `note`.

- **The five subjects are five columns, and "any" is `0` or `''`**
  (`Bans::ANY`). A row matches a request when every column it sets equals one
  of the request's, so one row can say "user 1001, but only from this
  address". Each is stored in the one spelling `Bans::value()` gives it -- a
  canonical address, a bare lowercase MAC, digits -- because it is compared
  with `=`, not parsed.
- **One row per set of subjects, held by the unique key `scope`** over all
  five. This is why "any" is not NULL -- the opposite of the clients table's
  `mac`: there NULL is wanted so empty values never collide; here an empty
  value has to collide, or two rules naming the same things could both exist.
  `Bans::described()` hands "any" out as null, so views test for one thing.
  An index on each other column, since a request names any of them.
- `state` is VARCHAR(16) rather than an ENUM, for the reason a resource's
  `type` is one.
- `expires_at` is set on a `banned` row and nothing else. Written and compared
  with the database's `NOW()`, never PHP's clock, for the reason Last Seen is.
- A row naming a client or a profile goes with it, since the next one written
  can be given the same id. A user is a number and its rows outlive it; a MAC
  names a handset and its rows outlive any client. [Overview](#overview)'s
  Delete All is the exception: it takes the bans naming the extension or MAC.
- `source` and `jail` are what created the row -- `manual` by default, or
  whatever adds bans on its own naming itself, with the fail2ban jail or rule
  that fired. Fields on a ban's page (not list columns), written on create and
  on an edit; a reopen keeps the row's own, so re-adding a ban never rewrites
  who made it. Validated as names, since they are drawn on the page.
- `hits` and `last_hit_at` count the requests a row *decided* -- the one row
  `decide()` picked, allow or not, not every row that matched. A hit assigns
  `updated_at = updated_at`, as a sighting does, so it never reads as an edit.
  Editing or reopening a ban keeps its count.
- `started_at` and `times` are the current ban period and how many periods
  there have been: a save that puts a row back in force (`saveBan()`, read
  inside the statement, before state and expiry are assigned), or fail2ban
  banning the address again, starts a new one. A row already in force keeps
  its own. **`ORYK_BAN_DENY_AFTER`** is decided at that moment and no other:
  `BanEscalation::apply()` makes a Banned row Deny (and `managed` 0) when the
  new period brings `times` to the setting or past it -- asked by `saveBan()`
  when `times` went up, and by `BanSync` for the rows fail2ban put back in
  force, which it then reconciles at once so the row moves into `deny`. A row
  set back to Banned by hand is not overruled until it next comes back into
  force.
- `synced_at` is when fail2ban was last seen holding this row's copy -- its
  ban, or, for an allow, the ignore entry the sync added. NULL is "fail2ban has
  nothing of ours for this row", which is what stops the sync lifting a ban it
  never made. See [Syncing with fail2ban](#syncing-with-fail2ban).
- `managed` is 1 on a row the sync follows: fail2ban's own ban, imported or
  revived by the sync. **Any save on the Bans tab sets it to 0** -- the ban is
  the person's from then on, whatever `source` says. It is a column of its own
  so `source` can keep saying what created the row.
- `deleted_at` marks a row deleted while it still has a copy in fail2ban that
  could not be lifted then (the sync paused, the helper failing). Such a row
  is out of `ACTIVE_EXPR`, the list and `banRow()` -- gone, to anyone looking
  -- and the minute job purges it once its copy is out. Adding the same ban
  again reopens it like any other row.

**`oryk_provisioner_services`** -- `slug` (the primary key), `name` (unique),
`owner` (0, the default, is the module; a service made on the Services page
is 1).
There is no numeric id: a page, a save, a delete, a link and an assignment all
name a service by its slug. Where a service sits is the table below.

- **The slug is the identity anything but a person uses**: lowercase letters
  and digits joined by hyphens (`Services::SLUG_PATTERN`). **It is never
  typed**: a save makes it from the name (`slugify()`, then `-2`, `-3` until
  free, stepping over the defaults') on a new service and again whenever the
  name changes. No page shows it but in its own address.
- **A slug that changes is carried to every reference in the same
  transaction** (`Services::renameLinks()`): today that is both columns of the
  links table and a user's assignments. Anything else that comes to store a service's slug must be
  added to that method, or a rename leaves it naming nothing. A default's
  slug never changes -- renaming one in `DEFAULTS` renames the row only.
- A table written before this had an `id` key, and before that no slug. An
  install adds the slug nullable (`Schema::addServiceSlugColumn()`), `seed()`
  fills it from each name, and `Schema::dropServiceIdColumn()` then drops the
  id and makes the slug the key -- in that order, which `Installer` keeps. The
  one `Schema` step that takes something away.
- **The module's own services are `Services::DEFAULTS`**, by slug: a name, and
  the slugs under it. **Adding, renaming or regrouping one is an edit to that
  array and nothing else** -- `install()` runs `Services::seed()`, which
  **deletes every row with `owner` 0 and writes the array again**, and sets
  what is under each to exactly what the array says. Like a setting, a
  change appears once the module is installed or upgraded. Assignments and
  links name a slug, so they stay with a default that is written again; a
  slug taken out of the array is deleted with its links and assignments, and
  the users who held it get a job revoking it -- install names each one
  removed and how many assignments went with it. **A default's slug that a
  release changes goes in `Services::RENAMED`** (old => new): `seed()` carries
  its links, assignments and jobs to the new slug first, and no job is made.
  An operator's row that holds a
  default's slug becomes the module's. `created_at` on a default is its last
  install.
- **"Managed" is `owner` 0**, on a row. A managed service is refused every
  save and delete and its page is drawn disabled with only Close.
  `Services::managed()` is the other question, asked of a slug: whether
  `DEFAULTS` has it, which is what keeps it from being given to an operator's
  service. `Schema::addServiceOwnerColumn()` backfills the column once, making
  every row outside `DEFAULTS` the operator's, and must run before `seed()`.
- **`seed()` owns only the links between two defaults.** One `DEFAULTS` no
  longer has is removed and one it has is added; a link with a service of the
  operator's own at either end is theirs and is left. So a managed service is
  locked on its own page, but a service of the operator's own can be put
  under it or over it from that service's page. A default link the
  operator's links would turn into a loop is not added, and install says so.
- A service already named like a default when the slug column arrives is given
  that slug by the backfill and so becomes the default, links kept. A
  default whose name another slug already holds is not written, and install
  says so.

**`oryk_provisioner_service_links`** -- `parent`, `child`: one row says the
child is under the parent. **Both are slugs, not ids**, so a link reads the
same in the table, in `DEFAULTS` and to anything outside the module.

- **A table of its own rather than a `parent_id` column**, because a service
  has many parents: "Voicemail" is under both "Basic User" and "Advanced
  User", and each of those is over many services.
- The pair is the primary key, so a link exists once. `child` has its own
  index for the walk upwards.
- A deleted service's links go by its slug, and a renamed one's follow it,
  so no link names a slug with no row.
- **No loop is ever written.** `Services::saveService()` refuses a set of
  parents and children when a service is on both sides or a parent is already
  somewhere under a child (`loops()`, pure, asked of the whole links table),
  and the editor draws those choices disabled (`related()`). Every walk stops
  at a row seen twice, so a loop put there by hand hangs nothing.
- **A save replaces a side whole, or leaves it alone.** `parents` and
  `children` are each the full set of slugs; a key that is not submitted leaves
  that side as it was. Name and links are one transaction.
- Deleting a service deletes its links and nothing else: what was over or
  under it stays.
- **A service pack is a service with at least one child**, read off the links
  (`listServices`' `kind` filter) and stored nowhere, so it cannot disagree
  with them. The list has no column for it. Its toolbar's two
  selects narrow it by `source` (`all`, labelled Available and what the page
  opens on, `custom` or `module`) and `kind` (`all`, which the page opens on, `single`,
  labelled Services, or `pack`). The command answers unnarrowed for a filter
  that is not one of its two values. Each option carries a count in its
  text -- what it would list with the other select left alone, from
  `Services::counts()` on every list answer, of every service whatever is
  searched for. They are option text, not badges. **The filters are in the page's address**
  (`&tab=services&source=module&kind=pack`, a default -- `all` for both -- left out;
  `Services::filters()` reads them and the selects are drawn chosen), so a
  change of filter is a page load, as a tab is. Both filters are asked in SQL so the page
  count stays true.
- A service's page is `?service=<slug>`, one tab. A save lands on
  the slug its answer names, which is a new address after a rename; saves and
  deletes are posted the row's id.

**`oryk_provisioner_service_assignments`** -- `extension`, `service`: one row
says the user has the service. A user is its extension and a service its
slug, as everywhere else; the pair is the primary key.

- **Only what was ticked is stored.** A user assigned a pack has one row, for
  the pack; the services under it are worked out when asked
  (`Services::userServices()`, which names the pack under `via`), so
  regrouping a pack changes what its users have without touching this table.
- **Staged, then one write** (`setUserServices`): the user's Services tab
  (`views/partials/user_services.php`) is two lists, packs and single
  services, whose ticks write nothing until its own Save -- which first asks
  `userServicesImpact`, the same request worked out and not written, and
  shows what would be assigned, unassigned, gained, lost and kept, and
  beside a gain or loss what the module's own job does about it
  (`Jobs\Job::effects()`, a phrase each for granted and revoked). The save
  names the services to assign and to unassign: each one's state is sent, not
  toggled, and one not named is not touched, so a page drawn before someone
  else's change cannot undo it. Nothing of the user itself is written, so
  there is no Apply Config.
- **It follows both ends.** A renamed service's rows follow its slug
  (`renameLinks()`) and a deleted one's go with it; a renumbered user's rows
  follow its extension (`Services::moveUser()`, from `ExtensionRenumberer`)
  and a deleted user's go with it (`forgetUser()`, from `Users::remove()`),
  since a freed number is handed out again.
- That tab and the navigator's
  Services level (`Services::assignedTo()`, `usersOf()`) count a service's own
  rows -- the users ticked for it, not those who have it through a pack. The
  Services list's one count, **Holders**, is of both (`holdersOf()`), and opens the Users
  list at `&scope=holders:<slug>`: a `service` scope with `held` set
  (`Navigator::scopeAt()`), which is the one place a scope follows the packs.
- **A template is the one reader that follows the packs.**
  `{{extension.services}}` is `Services::userSlugs()`: what the user is
  assigned and everything under it, each slug once, sorted, joined by commas
  -- a phone asks whether the user has a service, not how. What a change to
  them sets going is [Jobs](#jobs).

**`oryk_provisioner_jobs`** -- one change to what one user holds: `extension`,
`service` and `name` (the service the change was made to, the name a
snapshot; both '' for an upgrade, and for several services saved on a user
at once), `reason` (`assigned`, `unassigned`, `changed`,
`service-deleted`, `pack-changed`), `admin` (who was signed in when a page
made it, '' otherwise), `source` (`gui`, `upgrade`), `state`
(`queued`, `running`, `done`, `failed`), `attempts`, `error`, and when it was
made, last started and finished.

**`oryk_provisioner_job_steps`** -- what one job means, service by service:
`job_id` and `position` (revokes first, then grants, each by slug), `service`
and `name`, `via` (the assigned service it comes or went through, NULL for
the service itself), `event` (`granted`, `revoked`), `state` (`pending`,
`done`, `failed`, `skipped`), `done_by`, `own_job` (the `src/Jobs/` class
this module ran for it, shown beside `oryk_provisioner` on the job's page),
`attempts`, `error`.

- **Steps are rows, not a JSON column on the job**, so a renamed service is
  an UPDATE (`renameLinks()` carries `service`, and a step's `via`, with the
  rest) and a retry finds what is left by state.
- **Names are snapshots.** A job for a deleted service still says what it was;
  a page links a slug only while a service has it.
- **`done_by` is the handlers that have finished a step**, comma-separated
  rawnames. It is written as each returns, so a
  retry starts at the one that threw and never calls a finished one again.
- **A job belongs to its user.** `forgetUser()` deletes a deleted user's
  jobs, `moveUser()` carries a renumbered one's; there is no foreign key to
  Core.

## Jobs

A change to services is worked out, written and run as **jobs**: what makes
one, what one is, and how the worker runs it. `Services` writes them,
`Jobs` is the two tables, `ServiceEngine` runs them, `Reactions` is the
module's own handler.

**What makes a job.** Anything that changes what a user *holds* -- what it is
assigned and everything under that (`Services::held()`):

| change | users affected | reason |
| --- | --- | --- |
| a save of a user's Services tab (`setUserServices`) | that user | assigned / unassigned for one service, changed for several |
| delete a service (`deleteService`) | everyone holding it, assigned or through a pack | service-deleted |
| a service's Parents or Services changed (`saveService`) | everyone holding the pack it changes, at any depth | pack-changed |
| an install or upgrade regrouping the defaults (`seed()`) | likewise | pack-changed, source `upgrade` |

Each takes every user's assignments and the links before and after the
change, in the change's own transaction and under one named lock across
every change to services (`Services::lockChanges()` -- two at once would each
read what the other was about to change, and owe a revoke neither made), and `ServiceEngine::changes()` makes
the steps: a grant for what a user holds after and did not before, a revoke
for the reverse. So **a service still held another way is never revoked**
(Voicemail under both Basic and Advanced User), one already held is never
granted again, the same state saved again is no job, and a user a change
does not touch gets none. Several services saved on a user at once are one
change and at most one job, so swapping Basic User for Advanced User never
revokes the Voicemail both give. A rename is not a change: the before is read as if
it had always had the new slug. A renumbered user changes nobody's holdings
and gets no job; its jobs move with it.

**The service page asks first.** `serviceImpact` works out the same steps
without writing anything, so Save and Delete on a service say how many users
it changes, and how, before they do it.

**Running.** A change from a page starts `bin/oryk-jobs` in the background
(`Jobs::start()`, `nohup`), `--user=<ext>` for one user and with no argument
for many, and the request returns. A user's queue is run **one job at a time,
oldest first** (`ServiceEngine::drain()`), under a MySQL named lock per user
(`GET_LOCK`), which the server frees if the worker dies; a second worker for
the same user finds it held and leaves it to the first, which looks again
after letting go. Different users run side by side. Per job:

- the **claim** (`queued` or `failed` to `running`, `attempts` + 1) must
  change one row: a deleted job is not run;
- a **user not found** fails the job, and nothing is run. It is not deleted:
  Core saves an extension by deleting and adding it again, and a job claimed
  in between would take the whole queue with it. A user really deleted loses
  its jobs to the hook on Core's `delUser`;
- each step not yet `done` or `skipped` checks the **job is still there and
  still this user's** -- deleting one is how it is stopped, and a renumber
  moves it to the new number's queue, whose worker takes it from there -- and
  then that **it is still current**:
  a grant runs only while the user holds the service, a revoke only while it
  does not, else it is `skipped`. That is what makes running a failed job
  after a later one safe: a grant that failed and was then unassigned is never
  granted back;
- the step's **handlers** run one at a time: every module hooked to
  `serviceGranted` or `serviceRevoked`, in FreePBX's hook order -- **this
  one included**, through its own module.xml at priority 100, so first unless
  another asks for less. Its hook (`runOwnJobGranted()` / `runOwnJobRevoked()`
  → `ServiceEngine::ownJob()`) runs the `src/Jobs/` class for the service,
  under one lock across every worker (`Jobs::lockReactions()`): users' queues
  run side by side, and Voicemail's rewrites all of voicemail.conf. A service
  with a job of its own while FreePBX has not yet read that hook fails the
  step and says to reinstall, rather than passing with nothing run;
- **the first handler that throws stops the job there** -- its later steps
  stay `pending` -- with "<module>: <message>" on the step and the job, which
  is `failed`.

**A failure stops that job, for that user, and nothing else.** After each job
the worker runs the user's failed jobs older than it again (the automatic
retry), so a failure never holds up the jobs behind it and each later job
gives it another try; with nothing queued it stops, and a failed job waits
for **Retry** (back to `queued`, the worker started). Other users never wait on
it. A job left `running` by a worker that died is failed by the next holder of
its user's lock -- only a lock holder runs jobs -- and retried like any other.

**When the jobs are done, it reloads.** A run that ran at least one job, and
leaves none queued or running for any user, runs `fwconsole reload` if
FreePBX has Apply Config raised (`admin.need_reload`) -- once, after all of
them, under one lock across workers (`ServiceEngine::reloadIfIdle()`). A job
and a hooked module raise Apply Config and never reload themselves; the
worker that finishes last does it for all. **It applies everything pending**,
an admin's unapplied changes included, as Apply Config would; a minute run
that ran no job never reloads. A failed reload is logged and leaves Apply
Config raised.

**The minute job** (`bin/oryk-jobs`, registered by `install()`) runs every
user with a job queued or left running -- an upgrade's jobs, and anything whose
background start was lost -- and purges `done` jobs finished more than
`Jobs::KEEP_DAYS` (30) ago. It never retries a failed job on its own.
**`seed()` only queues**: an install may run as root, and handlers run as the
web user.

**Hooks out.** A module reacts by hooking ours in its own module.xml, the way
it would hook Core:

```xml
<hooks>
  <oryk_provisioner class="Oryk_provisioner" namespace="FreePBX\modules">
    <method callingMethod="serviceGranted" class="Mymodule" namespace="FreePBX\modules">onServiceGranted</method>
    <method callingMethod="serviceRevoked" class="Mymodule" namespace="FreePBX\modules">onServiceRevoked</method>
  </oryk_provisioner>
</hooks>
```

FreePBX files that under `FreePBX\modules\Oryk_provisioner` when the module
is installed or enabled. **A listener can be for some services only**:
`<method ... services="voicemail,call-recording">` -- FreePBX keeps every
attribute a method is declared with, and the worker skips the listener for any
other service (`ServiceEngine::onlyFor()`). A module may have several of these
for one event, each recorded in `done_by` as `rawname:method`; a catch-all is
recorded as the rawname, one per module. **The worker does not call `processHooks()`**: that
loops the listeners with no catch, so the first throw stops the rest with
nothing saying which, and leaves the thrower's text domain pushed. It reads the
same list (`Hooks::returnHooksByClassMethod()`, public in framework 16 and 17:
enabled modules, by priority) and calls each entry as FreePBX's own
`executeCall()` would, inside its own catch. `Oryk_provisioner::serviceGranted()`
and `serviceRevoked()` exist so the hook's `callingMethod` is a real method,
and run the same handlers for one event outside any job. What a handler is
given and owes is docs/hooks.md.

**Hooks in.** module.xml hooks Core's `delUser`: a user deleted anywhere in
FreePBX loses its assignments and jobs (`coreDelUser()` → `forgetUser()`).
Edit mode is a save -- Core deletes and re-adds the user -- and is passed
over. `ExtensionRenumberer` moves a user's services and jobs **before** it
deletes the old number, which this hook would otherwise take them with.

**The module's own reactions** are the classes in `src/Jobs/`, reached through
its own hook like any other module's: each extends
`Jobs\Job`, names the service slugs it is run for in `SERVICES`, and has
`granted()` and `revoked()`. **Adding one is adding a file** -- `Reactions`
finds them -- and they are written for `Services::DEFAULTS`, the module's own
services:
Voicemail makes a mailbox in `default` with a random PIN where there is none,
or takes it out of voicemail.conf and sets the extension `novm` -- **the
messages on disk are kept**; Call Recording sets the four recording keys to
`force`, or back to `dontcare`; On Demand Recording `enabled` / `disabled`;
Find Me Follow switches Find Me/Follow Me on (made with its own defaults where
there is none) or off, its list kept. Each sets a state, so a second run is the
first; none reloads, and one that changes what Apply Config writes raises it
for the reload after the last job. A
missing module fails the step and says so. Packs, Support, Guest User and Lobby
User have none: a context is changed in Extensions.

**Where it shows.** The Jobs section (`?tab=jobs`, filtered by state, reason and
source in the address, as Services is); a job's page (`?job=<id>`), its steps
and Retry; the Jobs dropdown; a user's Services tab, where each service whose
newest step is queued, running or failed says so (`Jobs::statusFor()`), asked
again while anything is running, with Retry beside a failed one; and a user's
Overview.

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

## Notices

A notice is a line over the section bar telling the admin what to do next --
"add your first profile". They are drawn on the module's pages only, every
one of them; FreePBX's dashboard has the module's two warnings instead
(`DashboardNotices`), which a minute job raises and these have nothing to do
with.

**A new notice is one entry in `Notices::definitions()`**: `id`, `version`,
`category`, `level` (`info` or `warning`), `dismissible`, `text`, the `action`
and `target` it leads to, and `when`, a closure answering whether it still
applies. Nothing else is written.

- **One per category, the first that stands.** `Notices::showing()` walks the
  definitions in order and, for each category, shows the first notice that is
  neither dismissed nor past applying. The order in the array is the order an
  admin is led through a category.
- **`when` is asked on every page load, and nothing is stored when it says
  no.** Make the first profile and "add your first profile" gives way to the
  next of its category; delete every profile and it is back. So a check is one
  cheap query at most. One that throws does not apply, and is logged: a broken
  check costs its notice, never the page.
- **A dismissal is the notice's id and the version dismissed**, kept in the
  module's key-value store (FreePBX's `kvstore`, through the BMO class's
  `getConfig()`/`setConfig()`) under `notices_dismissed`. It is PBX-wide, not
  per admin: a check is about the PBX, so is its dismissal.
- **Raising `version` shows a notice again** to a PBX that dismissed it. Raise
  it when the notice now says something an admin who dismissed it should
  read, and not for a reworded sentence.
- **Not `dismissible`** is for a notice about something wrong: it has no
  dismissal, the store is not read for it, and it stays until `when` says no.
- **On the page it leads to, a notice has no action** -- the page whose query
  has every key of `target`. The text stays; the button that would go nowhere
  is not drawn.
- **Dismissing does not reload.** `dismissNotice` posts the id and the version
  that was on the page, so a notice raised since is not dismissed unread, and
  is answered with the markup of whatever the category shows now
  (`Pages::dismissNotice()`, `views/partials/notice.php`), which
  `assets/oryk_notices.js` puts in its place. An editor's unsaved fields are
  left alone.
- The Settings tab's **Show Dismissed Notices Again** (`resetNotices`) removes
  the key. `install()` drops the dismissals no declared notice would read --
  an id that is gone, a version since raised -- and `uninstall()` the lot.
- No view names a notice: `Pages::view()` hands every view `$notices`, and
  `views/partials/sections.php`, which every view includes, draws them.

The categories are `welcome` -- first profile, first user, first client --
`settings`: a warning while Hostname is blank -- and `provisioning`: a warning
while Provisioning is Open and no profile is named `Default`, the one
`Profiles::profileFor()` falls back to.

## Users

A user is a row in none of this module's tables. It is a FreePBX extension,
with the `pjsip` device that is its own -- the one whose id is the extension
-- beside it (`Users::FROM`), so the device id, the extension and the User
Manager username are one number. Extensions made in FreePBX are listed too.
**The extension is what makes it a user, not the device**: an extension whose
device has been deleted is listed still (`device` 0 on its row, and no
context, email or From Domain, which the device held), is not a login for a
phone, has its Overview and its delete like any other, and is given a device
back by a save, on its own number. Only an extension whose number is held by
a device of another kind is left out (`Users::SHAPE`). Everything about one
lives elsewhere:

**A device on no extension is not a user.** A client's Device offers "Auto
Create" (`Clients::AUTO_DEVICE`): `Users::saveClientWithNewDevice()` makes a
`pjsip` device on the next generated number (`createDevice()`) and saves the
client on it, taking the device back out if the client is refused. The device's
`user` is `none` -- no extension, no User Manager account, no mailbox -- so it
is on no Users list, a client on it has no extension (`Clients` reads `none`
as NULL), and deleting it is `deleteDeviceById()`'s own branch.

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
| assigned services | `oryk_provisioner_service_assignments.extension` | `Services` |

**A save** (`Users::store()`) deletes the device and adds it again -- Core has
no edit -- so it starts from the device's stored settings, not driver defaults:
only keywords the driver names, plus `Users::CUSTOM`, are carried, so a setting
made in FreePBX survives and nothing stray is written to `sip`. On every save
`media_encryption=sdes` and `media_encryption_optimistic=yes` are forced, the
account, extension name, User Manager name and both emails are synced, EPM is
run, the endpoint file is written and **Apply Config is raised, never run**
(`needreload()`; the AJAX answer carries `reload` so a page that is not
reloaded raises the bar itself). A delete is the same. Nothing in the module
reloads. A blank number keeps the user's own (a new one
takes the one after the highest `999…` held, stepping over any number
`pjsip.endpoint.conf` still has -- a user deleted but not yet applied, whose
endpoint would win over a new sign-up's bridge rows; once applied, a freed
number is reused); a blank secret keeps the stored one. A number held
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

**A delete** removes every device on the extension -- its own, with its
endpoint section and bridge rows, and any other -- and **deletes** every client
pointing at any of them, whatever its MAC (`Clients::deleteForDevice()`, each
with its stored logs and its provisioning-log rows, as for any deleted client).
Then the extension, the account this module
owns, its UCP assignments and its **call history and recordings** go too, only
after the extension itself is gone. History is found by `src` or `dst` matched
exactly, then every row sharing a `uniqueid` or `linkedid` with those is
deleted from `cdr`, `transient_cdr`, `replicate_cdr` and `cel`; a recording is
unlinked only when no surviving record names it. A queue- or ring-group-
answered call carries the group in `dst` and is not matched. There is no undo,
which is why Delete says so before it asks.

**A user's clients are the ones on any device of its extension**
(`Users::CLIENT_OF`; `Navigator::owner()` for the dropdowns), not only those
on the device numbered like it: the Clients count, Last Seen, the Clients tab
(`listClients&extension=`) and every scope agree.

**The From Domain** is three questions, first answer wins: the device's own
`from_domain`; `ORYK_FROM_DOMAIN`, the [setting](#settings) in *Advanced Settings → Oryk
Provisioner* and on the Settings tab;
`ORYK_HOSTNAME` unless it is an address, otherwise this machine's hostname --
either only when it is a domain name (not bare, not `.local`, not
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

A ban is a row of `oryk_provisioner_bans`: up to five **subjects** -- client,
user (an extension), MAC, profile, address -- each empty for "any", and a
**state**:

| state | does | until |
| --- | --- | --- |
| `banned` | refuses | `expires_at`; the row then stays, expired, until deleted |
| `deny` | refuses | the row is deleted |
| `allow` | answers in spite of a less specific ban | the row is deleted |

A row **matches** a request when every subject it names is one of the
request's. "Address 203.0.113.7" matches every phone behind it; "user 1001,
address 203.0.113.7" matches only that user from there.

**The endpoint asks before it answers anything.** `Endpoint::banned()` runs
first in `serve()`, `receive()` and `openProvision()`, and hands
`Bans::decision()` -- the deciding row, an allow included; `check()` is it
with an allow read as nothing refusing -- every subject the request has: the address it came from
(`REMOTE_ADDR`), its MAC, the client that MAC names, that client's device id
and extension, and the profile it is served -- its own, or, with none, the one
`Profiles::profileFor()` picks, as `resolveRequest()` would -- or, for open provisioning, the
username, which is a user when it is a number. A file fetched by name with no
client behind it has no profile, so a profile ban does not stop it. A subject the request does not have matches only rows that
leave it empty. Open provisioning is asked before `openClient()`, so a refused
caller never makes a user, and an allow that decides lifts its sign-up limits; the client it is answered as is checked again by
`serve()`. A refusal is a 403 through `answer()`, so it is logged like any other
request, and touches nothing else -- no `last_seen`. The caller's body is a bare
`Forbidden`; which ban, and what it names, go to the provisioning log only,
since they would map a MAC to its extension. The deciding row is counted
a hit (`Bans::hit()`), once per PHP request, so open provisioning asking twice
counts once; that UPDATE is the only write on the request path, and one that
fails is a count lost, never a request refused.

**The most specific row decides** (`Bans::decide()`): the one whose most
specific subject comes first in `Bans::SUBJECTS` -- client, user, MAC,
profile, address -- then the one naming more subjects, then a refusal over an allow. If
it is `allow` the request goes on, otherwise it is refused. So:

- an allowed client is served from a banned address;
- "allow user 1001 from 203.0.113.7" beats "deny user 1001";
- an allowed address does not rescue a denied MAC, or a denied profile.

A profile ranks below a MAC and above an address: it is what a phone is,
where an address is only where it is.

`allow` overrides bans and nothing else: a disabled client, a token or a
profile still decide afterwards as they always do.

- **It fails open.** A table that cannot be read refuses nothing, so new files
  on a PBX not yet upgraded keep provisioning.
- **An expired ban is never in force, and never deleted for it.**
  `Bans::ACTIVE_EXPR` is the one test: `check()` filters on it, and the list
  reads it as `active` to grey the row and mark it expired. A row goes only
  when someone deletes it; saving it again with minutes puts it back in force.
- **A ban for the same subjects is reopened, never duplicated.** A new ban
  naming exactly what a row already names is an
  `INSERT … ON DUPLICATE KEY UPDATE` that writes its state, expiry and note
  (when one is given) over that row, expired or not, and answers with its id,
  so the editor lands on it. The key decides, so two saves at once still make
  one row. An existing ban edited onto another row's subjects is refused: that
  would be two rows becoming one.
- **Nothing else is refused on save** but a subject that is not one of its
  kind, a row naming nothing, and a refusing ban on loopback or the
  unspecified address. With the sync on, an IP-only ban reaches the firewall
  (below), so the editor warns when an address on its own is yours or a
  client's public address.
- A ban's page is `?ban=<id>`; it is edited like any other row, and a
  temporary ban saved again runs its length from that save.

## Syncing with fail2ban

fail2ban is IP-based, so only **IP-only** rows -- an address and every other
subject "any" -- are synced, both ways. A ban on a client, user, MAC or
profile stays the provisioner's.

| row | in fail2ban |
| --- | --- |
| Banned | banned in **`banned`** until the row expires |
| Deny | banned in **`deny`** until the row is deleted |
| Allow | on each **managed** jail's ignore list, and unbanned in each one it is banned in |

The **managed jails** are `banned`, `deny`, and the jails root lists in
`/etc/oryk-fail2ban.conf` -- the Asterisk and FreePBX ones. The helper reads
that file itself and sees no other jail: it cannot list, unban in or add an
ignore entry to `sshd`, `recidive` or anything else root left out. Blocking
stays broad (both module jails ban every port); only what loosens fail2ban is
narrowed, so an Allow never exempts an address from SSH protection.

**fail2ban answers root only**, and the GUI and FreePBX's scheduler run as the
web user. So `Fail2ban` -- the only file that asks -- runs
`sudo -n /usr/local/sbin/oryk-fail2ban <verb> …` with an argument array. The
helper (`bin/oryk-fail2ban`, Python) is the privilege boundary: `check`,
`list`, `ban <banned|deny> <ip>`, `unban <jail> <ip>`, `ignore <ip>`,
`unignore <ip>`; every argument re-checked (one address, no range, no
loopback; a managed jail fail2ban has), one line of JSON back. It refuses a
`/etc/oryk-fail2ban.conf` that is a link or that anyone but root can write. Exit 64 is a refused
argument, 69 fail2ban down. It asks fail2ban over fail2ban's own socket with
fail2ban's own client library (`fail2ban.client.csocket`), one process for a
whole `list`; where `/usr/bin/python3` cannot import that library it falls back
to `fail2ban-client` with a fixed argument list -- the same answers, one Python
start-up per question. `Fail2ban::status()` is what the Bans tab shows under
the table; Installer and Pages hold `Fail2ban`, only `Bans` holds `BanSync`.

- **The helper sudo runs is a root-owned copy, never the module's file** --
  the module directory is writable by the web user.
- **Setup is one script**, `bin/oryk-fail2ban-setup`, run as root by hand or
  by `install()` when that runs as root: helper copy, a sudoers file checked
  with `visudo` before it is renamed into place (sudo skips dotted names),
  `/etc/oryk-fail2ban.conf` (`--jails "<jail> …"`, or on first install the
  loaded jails named `asterisk*`, `freepbx*` or `pbx*`; kept on later runs),
  and the module's two jails, `banned` and `deny` (`jail.d/<name>.conf`, a
  filter that never matches, `bantime = -1`, `banaction =
  %(banaction_allports)s`). Permanent in fail2ban, because fail2ban takes no
  ban time per address: the sync lifts a Banned row's copy when the row
  expires. It refuses to write over a jail of either name it did not write;
  `--check` reports, `--remove` undoes.
  `HELPER_VERSION` finds a stale copy. Nothing about it fails an install.
- **The minute job** is `bin/oryk-fail2ban-sync`, registered by `install()`
  with FreePBX's scheduler (`Job::addCommand`, every minute, as the web user)
  and removed by `uninstall()`. It bootstraps FreePBX and calls
  `BanSync::run()`.
- **A save is carried over at once.** `Bans::saveBan()` -- and
  `setBanState()`, the table's State menu, which saves the row again with only
  its state changed -- hands the row before and after to
  `BanSync::afterSave()`: the old copy is lifted if the row moved (address,
  scope or state changed), then the address is reconciled.
  `deleteBan()` lifts first -- once the row is gone nothing says it was ours --
  and when that cannot be done, marks the row deleted (`deleted_at`) for the
  minute job to lift and purge.
  A helper failure never fails the save; the minute job retries.
- **`ORYK_FAIL2BAN_SYNC`** (a [setting](#settings), on by default) pauses it:
  nothing is read or written, and nothing already in fail2ban is undone.

**One run** reads fail2ban (`list`: the managed jails' bans with ban time and
bantime, and their ignore lists) and the IP-only rows, and `BanSync::plan()` --
pure, and tested case by case -- decides:

- **fail2ban → table, every managed jail but `banned` and `deny`.** An address with no row gets one
  (source `fail2ban`, the jail, Banned -- or Deny when fail2ban's bantime is
  permanent -- with fail2ban's ban time and expiry, `managed` 1). A managed
  row is refreshed, `times` up when the ban time moved. **A row in force that
  the sync does not manage is never changed**; one not in force is revived
  like a reopen and managed again, source kept. An address banned in several
  jails is one row: the longest ban. A managed row in force that fail2ban no
  longer has is expired, never deleted -- until `BanSync::KEEP_DAYS` after it
  expired, when a run with no address given prunes it (managed, source
  `fail2ban`, no copy in fail2ban). That bounds the table by how many addresses
  fail2ban bans in that time; an address back after it starts `times` again.
- **table → fail2ban.** `banned` and `deny` are mirrored exactly: each holds
  the rows of ours in force in that state, and anything else in them is
  unbanned. So one rule lifts a copy whether its row expired, changed state or
  was deleted, and a fail2ban ban a person turns into a Deny is lifted from its
  jail and banned in `deny`. A row of ours missing from its jail is banned; one
  already there is confirmed. An allow missing from any managed ignore list is
  added; one already on every one, without `synced_at`, was put there by someone else
  and is never removed by the sync.
- **A row marked deleted** has its copy lifted -- from the module's jails by
  the mirror, an allow off the ignore lists, a ban the sync followed from
  fail2ban's own jail -- and is purged once every lift succeeded. Nothing is
  imported onto its address meanwhile.
- **Never pushed**: loopback, unspecified, or the PBX's own addresses --
  every interface's (`net_get_interfaces()`), the request's, the hostname's.
  The minute job has no request, so the interfaces are what it relies on.
- **fail2ban unreadable is not "no bans"**: the run does nothing, or every
  `fail2ban` row would expire the moment fail2ban restarts.
- Writes assign `updated_at = updated_at` and are guarded in SQL against a row
  that changed since it was read; a row is marked synced only once the helper
  said yes. Each push and lift is a line in the FreePBX log.

**Known limits.** Each push is one `Ban` line in fail2ban's log, which
`recidive`, where it is enabled, counts like any other ban. An ignore
entry added at runtime is lost when fail2ban restarts and re-added by the next
run. An Allow lifted from the ignore lists comes off every managed jail, including
one where an administrator had listed it too. A ban followed from a jail later
dropped from `/etc/oryk-fail2ban.conf` expires on the next run, and deleting it
lifts nothing. Times: fail2ban prints local time;
the helper turns it into epochs and the sync writes them with
`FROM_UNIXTIME()`, on the database's clock.

## The Realtime bridge

A sign-up must work before anyone presses Apply Config, and the module never
reloads. So its endpoint, auth and AOR are also written to three tables --
`oryk_provisioner_ps_endpoints`, `_ps_auths`, `_ps_aors`, `id` the key, every
other column a VARCHAR named as the Asterisk option it holds
(`RealtimeBridge::COLUMNS`) -- and Asterisk is told to read them:

- `extconfig.conf` maps `ps_endpoints`, `ps_auths`, `ps_aors` to them over
  `RealtimeBridge::ODBC`, FreePBX's CDR connection, which logs in as the
  FreePBX database user; the tables are named database-qualified
  (`AMPDBNAME.table`) for that reason;
- `sorcery.conf` `[res_pjsip]` maps each type to `config,pjsip.conf` first and
  `realtime` second -- so once Apply Config has written an id to the files,
  the files win and the row is ignored. A `sorcery.conf` that already has a
  `[res_pjsip]` is not touched: two mappings would read the files twice.

Both are a block between `RealtimeBridge::BEGIN` and `END`, added or replaced
by `install()` and taken out by `uninstall()`. A sorcery mapping is read when
Asterisk starts, so it needs one restart. `available()` -- tables there, both
blocks there -- gates every write; without it a sign-up works from the next
apply, and nothing fails.

The rows (`RealtimeBridge::rows()`, pure) are built from the device as Core
holds it after `store()`, named as FreePBX names its own (endpoint and AOR the
extension, auth `<ext>-auth`), with the device's own context, secret and media
encryption, and `allow_transfer=no` in the lobby. An edit, renumber or
delete of a user in the bridge rewrites or removes its rows, or its phone would
keep the old password or context until the apply.

**The sweep** (`SignupSweep`, `bin/oryk-signup-sweep`, every minute, as the
web user) never reloads. While FreePBX's need-reload flag is up it does
nothing. Once it is down, a `created` client whose extension has a section in
`pjsip.endpoint.conf` -- or whose user is gone -- has its rows removed and
becomes `provisioned`. The flag alone is not enough: a sign-up during an apply
can end with the flag down and its endpoint not yet written. It also keeps the
dashboard notices true (`DashboardNotices`, raised or cleared on a change only):
`BRIDGE_STALE` while any sign-up has waited over a day, and `OPEN_CAP`, raised
by `admit()` when `ORYK_OPEN_PER_DAY_TOTAL` refuses and cleared once the day's
count is back under it.

**Unverified**: ODBC, the qualified table names, the sorcery mapping and that
a registered contact survives the move from row to file are on the
open-signup plan's checklist for a test PBX.

## Overview

`?tab=overview&scope=user:<ext>` or `&scope=client:<id>`: everything tied to
one user or one client on one page, each part removable there, and all of it
by **Delete All**. It is a section like the lists, and the `&scope=` is the
lists' own (`Navigator::scopeAt()`); `Overview::target()` keeps a user or a
client and reads anything else as no row, which is the prompt to choose one.
Choosing is the dropdowns: on Overview the Users and Clients options re-open
Overview on the row chosen (`Navigator::levels($at, 'overview')`), while the
crumb's name still links to the row's own page. From a user's or client's
page, the section bar's Overview opens on that row. A row on the Users
list has no delete, only an Overview button that leads here; a client is deleted in place on the
Clients list, as a profile is on its own.

`Overview::inventory()` is the one answer to "what is tied to it", read by the
pane, by every command and by Delete All:

| | user | client |
| --- | --- | --- |
| clients | every client on any device of the extension | itself |
| provisioning log | rows with those clients' MACs | rows with its MAC |
| stored phone logs | `LogRepo::clientLogStats()` of each | its own |
| bans **naming** it | by `client_id`, by the extension, by one of the MACs | by `client_id` or its MAC |
| bans that only **apply** | the rest of `Navigator::scope()`'s `bans` | same |
| FreePBX side | `Users::related()`: owned account, mailbox | -- |

The pane is tables -- User, Clients, Provisioning log, Bans, and on a user
Devices, Call history, Voicemail and Jobs (the Jobs list's own `listJobs`,
asked with the scope) -- on either kind of row: a client's Overview lists its user,
which is kept, and itself. Call history is `CdrHistory::listCalls()`: the
records naming the extension in `src` or `dst`, which is where `purge()`
starts, not every leg it removes. Devices are the FreePBX devices on the
extension (`Users::listDevices()`). Each row says whether the device is registered
(`DeviceStatus`): registrations are Asterisk's and in no FreePBX table, so it
is one manager command per device listed, `pjsip show aor <id>`, the id
checked against `DeviceStatus::ID_PATTERN` before it is written into it --
asked by the list command for the rows on the page, never for every device.
A device's trash can deletes the
device and nothing else of the user's (`Users::deleteDevice()`: Core's
`delDevice()` and its endpoint section). The question it asks decides the
clients pointing at it: Device Only keeps and unassigns them
(`Clients::unassignDevice()`), Device + Client deletes them. From the other
side, a client's delete offers Client + Device
(`Overview::deleteClientWithDevice()`), which deletes the device it used by
the same path. On the
user's own device that leaves the extension, account, mailbox and history
standing: still a user, listed with no device, until a save gives it one back
or Delete All takes the rest. 
Voicemail is read off the spool
(`VoicemailManager::messagesIn()`): every `<folder>/msgNNNN.txt` under the
mailbox, the greetings beside the folders left out.
The User table names the account this module owns for it, as a link to it in
User Manager.

**Naming is not applying** (`Overview::names()`). A ban reaching the row only
through its profile or an address is about something else: it is listed,
marked Keeps, and Delete All leaves it. One naming the client, its MAC or the
user's extension is marked Removes -- including the extension and MAC bans
that survive an ordinary delete (see [Schema](#schema)), since here cleaning
up after the row is the point and a freed number is handed out again.

**Every command is posted the scope** -- `listOverviewUsers`,
`listOverviewDevices`, `listOverviewClients`, `listOverviewBans`, `listOverviewCalls`,
`listOverviewVoicemail`, `clearOverviewHistory`,
`clearOverviewVoicemail`, `clearOverviewJobs`, `clearOverviewLogs`, `clearOverviewStored`,
`purgeOverview` -- and works out what is related from it, so nothing is
deleted by an id a page sent. Two take an id as well, and accept it only among that user's own:
`deleteOverviewVoicemail`, a message the walk of its mailbox produced, and
`deleteOverviewDevice`, a device on its extension. `clearOverviewLogs` is a command of its own because
`clearLogs` with nothing narrowing it is the whole log;
`ProvisioningLog::clearFor()` with no MACs deletes nothing.

**Delete All** (`Overview::purge()`): the bans naming it first, each through
`Bans::deleteBan()` so a copy in fail2ban is lifted, and a ban that cannot be
deleted stops it there, before the row goes; then `Users::deleteUser()` -- the
whole of a user's [delete](#users), clients and FreePBX side included, Apply
Config raised -- or `Clients::deleteClient()`. A client's user is shown and
never deleted.

**Clear Call History** (`clearOverviewHistory`) is the one part of the FreePBX
side that can go on its own: `CdrHistory::purge()` exactly as a user's delete
runs it -- so a call with another extension leaves that one's history too --
with the user kept. **Clear Voicemail** (`clearOverviewVoicemail`) unlinks
the messages `messagesIn()` lists, audio included -- one walk
(`messageFiles()`) behind both -- and keeps the mailbox, its greetings and
its voicemail.conf entry; Asterisk's own mailbox poll is what brings message
waiting back in step. One message goes with `deleteIn()`, which renames the
folder's later messages down a place: Asterisk numbers them without holes. The account and the mailbox itself still go only with
the user.

The tables on the pane are the lists' own -- same ids and formatters -- so
`views/admin.php`'s handlers answer their buttons, and a client's trash can
deletes in place there. Users and Clients are asked through Overview's own
list commands, not `listUsers`/`listClients&scope=`: a row's own level is
never narrowed by `Navigator::scope()`, and the rows carry what only Overview
shows (the owned account; a client's stored logs, with their own
delete). What Delete All asks is counted when the page is drawn, so a delete
made from one of the tables is answered by loading the page again.

## Conventions that hold everywhere

- **Everything the module edits is a page**, told apart by which key the URL
  carries: `?client=`, `?profile=`, `?profile=<id>&resource=`, `?user=`,
  `?service=`, `?ban=`, and `?log=` for one provisioning log entry and `?job=`
  for one job, which are read and deleted but never edited or created. The
  key present and empty is the "new one" editor. `Pages::doConfigPageInit()`
  bounces an id that names no row *before any markup* -- a redirect out of
  `showPage()` would be too late to set a header. A user's key is its
  extension, so a renumbering save lands on a new address.
- **Every page is topped the same way, and each strip means one thing.**
  `views/partials/sections.php` is the module's sections, a bar on every page,
  lit by the branch the page is in (a resource page is in Profiles). Sections
  listed together in `Navigator::sectionGroups()` share one entry on the bar
  -- Users, Clients, Profiles and Services do, and Logs, Bans, Jobs and Overview. The entry is
  a link to the group's active section, else its first, and hovering or
  focusing it opens a menu of the group's sections; grouping more is a line in
  that one array.
  `views/partials/navigator.php` is a row of eight searchable dropdowns under
  it -- Users, Clients, Profiles, Resources, Services, Logs, Bans, Jobs -- scoped by the row the
  page is viewing (user 1-n client n-1 profile 1-n resource): each lists only
  what is linked to that row, the viewed row's own level lists all of its kind
  with it selected, and nothing else is ever selected: a linked level with
  exactly one row lists that one row.
  Services follow Users: the level lists what the users in scope -- the viewed
  user, else the Users level's -- are assigned themselves, never what reaches
  them through a pack. From the other side, a service scopes the users
  assigned it themselves, and their clients, profiles, logs and bans.
  Jobs follow Users too -- the jobs of the users in scope -- except on a
  service, whose are the jobs changes to it made; a job scopes like its user.
  Jobs lists only the newest `Navigator::JOB_LIMIT`.
  Logs are linked by MAC: a client's own, a user's or profile's clients'. Logs
  lists only the newest `Navigator::LOG_LIMIT` entries in scope, and its badge
  counts those; an unscoped Bans level likewise lists the newest
  `Navigator::BAN_LIMIT` (plus the viewed ban). Bans lists the bans that *apply* to the viewed row
  (`Bans::applies()`, check()'s test on one row): every subject a ban sets must
  be one of the row's requests', a client's address being the public one it
  was last seen at; a user or profile is its clients' requests plus the bare
  user or profile. From the other side, a ban scopes the clients it applies to
  and their users, profiles and logs (an address-only ban: logs from that
  address); a log entry scopes like the client with its MAC, and its Bans are
  those that apply to that request. A page viewing nothing (lists, Settings)
  scopes nothing. A level's title links to the table of what it counts: the
  viewed row's own tab where it has one (a user's Clients or Services, a client's Logs),
  else the section's list with `&scope=<kind>:<id>` naming the viewed row; an
  unscoped level's title is the whole list. `Navigator::scope()` is the one
  computation behind both the dropdowns and every list command asked with
  `scope`, so a table counts what the badge did -- bar Logs and unscoped Bans,
  whose badges stop at `LOG_LIMIT` and `BAN_LIMIT`. A scoped Bans level reads only
  the rows naming one of the viewed row's subjects (`Bans::banChoices($requests)`)
  and asks `applies()` of those, never the whole table. A scoped list draws the dropdowns as that row's page does,
  says what it is narrowed to, and has no Clear on Logs ([Overview](#overview)
  has its own). A tab strip is
  only ever the views of the one row that is open; the list page has none.
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
- **Styles live in `assets/oryk_provisioner.css`**, never in a `<style>` in a
  view. `Pages::view()` puts it on every page: linked through the
  `admin/assets/oryk_provisioner` symlink `fwconsole reload` makes, with the
  file's mtime as `?v=`, or inlined until that symlink exists. It is outside
  `assets/css/` on purpose -- FreePBX links everything in there itself, with
  no version, so an edit would be served from cache and the file loaded twice.
- **No view contains a `<form>`.** The module page renders inside the FreePBX
  page form and a nested form is dropped by the browser. Fields are read by id
  and posted with an explicit `$.ajax({type: 'POST'})` to `ajax.php`.
- **A question is `orykAsk()`, never `window.confirm()`.** `assets/oryk_dialog.js`,
  put on every page by `Pages::view()`, draws a FreePBX modal and returns a
  promise: resolved with the value of the choice pressed, rejected by Cancel,
  so `.done()` is the yes and nothing handles the no. It takes `choices` where
  a question has more than one yes -- a device's delete asks Device Only or
  Device + Client, a client's Client Only or Client + Device -- and an empty
  message asks nothing and resolves. The handler that asks uses an arrow
  function for the answer, so `this` is still the button.
- **Action bar buttons are `oryksave` / `orykdelete` / `orykclose`**, and
  Overview's `orykpurge`, not the
  `submit`/`delete` core wires to a `form.fpbx-submit` none of these pages has.
- **Icons are SVGs in `assets/icons/`, never Font Awesome.** `Icons` reads
  them; a view prints one with `$icon('<name>')` (handed to every view by
  `Pages::view()`), and JavaScript with `orykIcon('<name>')`, which the same
  call puts on every page. Both are inline, so an icon is the colour of the
  text around it. A table's refresh button is bootstrap-table's own, so the
  table names ours with `data-icons-prefix="oryk-icon"` and
  `data-icons='{"refresh":"oryk-icon-refresh"}'`, and `orykFillIcons()` swaps
  the empty `<i>` it draws for the SVG. A new icon is a new file: one 24-unit
  outline with `stroke="currentColor"`, named `[a-z0-9-]`.
- **A field's help is FreePBX's (?) icon.** FreePBX hides every
  `.fpbx-help-block` until a `<i class="fpbx-help-icon" data-for="<id>">`
  (holding `$icon('help')`) beside the label is hovered, and then shows the
  one element with id `<id>-help` -- so help without that pair is never seen.
  A field with several paragraphs puts them in `.oryk-help-part` spans inside
  one block.
- **The only badges are in the navigator.** A dropdown's title carries the number of
  options that dropdown lists, from `Navigator`, so it always matches its menu
  and is scoped like it; an option may carry one saying what kind of row it
  is -- a service pack is "Pack", read off the links (`Services::packs()`). Neither the section bar nor a tab strip carries one,
  and none is ever read off a table: a table with something in its search box
  answers with the total of what matched. They are drawn with the page and not
  refreshed in place.
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
  `ajaxHandler()`. **One that only reads is also named in
  `Oryk_provisioner::READS`; every other command is answered only to a
  POST**, so a link or an image on another page cannot make one.
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
  A new client is given a token when saved without one, but an update can
  still take it away.
- Uniform refusals. A failure still says which kind of failure it was, so a
  caller probing MACs can tell a known one from an unknown one. Closing that is
  the token scheme's job and is a change to all the messages at once.
- No rate limiting or lockout on token verification. Open provisioning's
  sign-ups are limited per address and per PBX, but a wrong password for an
  existing login reaches fail2ban only through FreePBX's security log, and the
  limits trust `REMOTE_ADDR` -- behind a proxy every sign-up is one address.
- Copying resources between profiles, and exporting or importing a profile. A
  profile is made empty or from the [library](#the-library).
- Nothing reads an entry's `skus`: a phone's model is
  not detected, and a client with no profile is still served the profile named
  after its vendor, or `Default`.
- No `fwconsole` command. Backup/restore hooks are stubs.
- Only Connect's Extension/User kind was ported. Handsets are clients here;
  Connect's softphone and RTSP kinds have no equivalent, and an RTSP device
  needs Connect's driver installed.
- Renumbering does not check ring groups, queues or other destinations for the
  old number. A PBX-wide From Domain change is not pushed to existing endpoints.
- GraphQL API, per-client parameter overrides, template filters/sections.
  Escaping is one rule: a template beginning `<?xml` has its values
  XML-escaped (`Template::renderConfig()`); nothing else is escaped.
