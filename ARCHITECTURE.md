# Architecture

How the module is put together, and why. `README.md` is the operator-facing
account of what it does; `CLAUDE.md` is the working brief for an agent editing
it. This file is the one to read before changing behaviour.

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

## Request flow

```
phone
  -> engine/.htaccess          rewrites anything under engine/ to provisioner.php
  -> engine/provisioner.php    who is asking (MAC) + what they asked for (last
                               path segment). Bootstraps FreePBX directly:
                               freepbx_auth=false, restrict_mods=true
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
src/                         22 files, namespace FreePBX\Modules\Oryk_Provisioner
views/                       one view per page, plus views/partials/
```

`src/`, roughly in dependency order:

| file | what it is |
| --- | --- |
| `Service` | base class: FreePBX handle, PDO, the four table names, `rowCount()` |
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
| `Installer` | install, uninstall, the web-root symlink |

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

## Conventions that hold everywhere

- **Everything the module edits is a page**, told apart by which key the URL
  carries: `?client=`, `?profile=`, `?profile=<id>&resource=`. The key present
  and empty is the "new one" editor. `Pages::doConfigPageInit()` bounces an id
  that names no row *before any markup* -- a redirect out of `showPage()` would
  be too late to set a header.
- **A tab is a link.** `?tab=` is read server-side, only the pane asked for is
  rendered, and `views/partials/tabs.php` draws the rest as links. A tab with
  nothing behind it is not drawn. Nothing about tabs is scripted. The
  consequence: Save is drawn only on the editor's own tab, because on any other
  tab the fields it posts are not on the page.
- **No view contains a `<form>`.** The module page renders inside the FreePBX
  page form and a nested form is dropped by the browser. Fields are read by id
  and posted with an explicit `$.ajax({type: 'POST'})` to `ajax.php`.
- **Action bar buttons are `oryksave` / `orykdelete` / `orykclose`**, not the
  `submit`/`delete` core wires to a `form.fpbx-submit` none of these pages has.
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

- The README's `token=…&filename=…` URL scheme. Tokens are opt-in per client
  and nothing makes you set one.
- Uniform refusals. A failure still says which kind of failure it was, so a
  caller probing MACs can tell a known one from an unknown one. Closing that is
  the token scheme's job and is a change to all the messages at once.
- No rate limiting or lockout on token verification.
- Copying resources between profiles, or a seeded starting resource. A profile
  is set up one file at a time from empty.
- No `fwconsole` command. Backup/restore hooks are stubs.
- GraphQL API, per-client parameter overrides, a bundled vendor template
  library, template filters/sections/escaping.
