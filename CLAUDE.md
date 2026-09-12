# CLAUDE.md

Guidance for Claude Code working in this repository.

## Hard rules

- `.vscode/sftp.json` points a save-watcher at a live PBX
  (`/var/www/html/admin/modules/oryk_provisioner`). Assume anything written
  here can reach that box. `.vscode/` is gitignored; do not add files to it.

## What this is

A FreePBX 16/17 module (`rawname` `oryk_provisioner`, BMO class
`Oryk_provisioner`) that provisions VoIP phones. A **client** is a MAC address,
optionally a FreePBX device and optionally a **profile**; a profile serves
**resources**, and a resource is a filename plus a type. A phone fetches
`/provisioner/<mac>-phone.cfg` and gets that profile's `phone.cfg` rendered for
that client.

It is Stage 1 of a larger design. `README.md` is the operator-facing account and
is the best single orientation document; read it before changing behaviour.

## Layout

```
Oryk_provisioner.class.php   BMO contract + an autoloader for src/ + the AJAX
                             dispatch table. A thin adapter since 1.0.13
src/                         22 files, namespace FreePBX\Modules\Oryk_Provisioner
views/                       one view per page, plus views/partials/
engine/provisioner.php       the anonymous endpoint a phone reaches
engine/.htaccess             rewrites everything under engine/ to provisioner.php
page.oryk_provisioner.php    one line into showPage()
```

`install()` symlinks `engine/` to `<AMPWEBROOT>/provisioner`, which is the short
URL phones are given. Nothing about that link is allowed to fail the install.

Everything in `src/` extends `Service` (FreePBX handle, PDO handle, the four
table names, `rowCount()`), except `Mac` (static) and the two traits, `Logs` and
`Enabled`.

Tables: `oryk_provisioner_clients`, `_profiles`, `_resources`, `_logs`.

## Conventions that hold everywhere

- **Everything the module edits is a page**, told apart by which key the URL
  carries: `?client=`, `?profile=`, `?profile=<id>&resource=`. The key present
  and empty is the "new one" editor. `Pages::doConfigPageInit()` bounces an id
  that names no row, before any markup.
- **A tab is a link.** `?tab=` is read server-side, only the pane asked for is
  rendered, and `views/partials/tabs.php` draws the rest as links. A tab with
  nothing behind it yet is not drawn. Nothing about tabs is scripted.
- **No view contains a `<form>`.** The module page renders inside the FreePBX
  page form and a nested form is dropped by the browser. Fields are read by id
  and posted with an explicit `$.ajax({type: 'POST'})` to `ajax.php`.
- **Action bar buttons are `oryksave` / `orykdelete` / `orykclose`**, not the
  `submit`/`delete` core wires to `form.fpbx-submit`. `views/partials/editor.php`
  binds them.
- **Tab badges come from `Counts`, never from the table they label** — a table
  with something in its search box answers with the total of what matched.
- **A table name or a column name written into SQL is interpolated, so it may
  never come from a request.** Sort columns are whitelisted and mapped;
  everything else is bound.
- **A MAC is twelve lowercase hex characters, separators stripped**
  (`Mac::normalize()`). `Mac::stored()` is the log's variant, which keeps what
  was sent when it was not a MAC.
- A new AJAX command must be named in **both** `ajaxRequest()` and
  `ajaxHandler()`.
- PHP and views are indented with **tabs**. Strings shown to an operator go
  through `_()`.

## Things worth knowing before changing behaviour

- `Endpoint::resolveRequest()` decides; `serve()` / `receive()` end the request.
  Anything that wants an answer without the exit calls `resolveRequest()`.
  Order matters: disabled client, then disabled profile, then match, then the
  token check, then the resource's type. The token is asked for *after* a match
  and *before* the type, so a 401 cannot reveal which files exist.
- Only a `200` counts as a sighting (`Clients::touchClient()`), and that write
  assigns `updated_at = updated_at` on purpose so a phone booting does not read
  as a client somebody edited.
- `Matcher::matchResource()` matches a rendered name first and a bare tail
  second (`resourceSuffix()` takes this client's MAC off the front).
  `resourceRequest()` is the same thinking backwards and is what the Render
  links are drawn from — a name carrying *another* device's MAC gets no link.
- `Schema` steps are additive and ask `information_schema`, never a dbversion.
  `install()` calls them in order, and `addResourceTypeColumn()` must stay after
  `addResourceFileColumns()` because it backfills from `file_size`.
- A resource's `type` is the only thing that says what it is. Do not infer a
  kind from `file_size` or from the template column — that inference is what
  1.0.14 removed.
- Migrations are deliberately not written for renamed tables or dropped columns
  (1.0.7, 1.0.8): Stage 1 has no installed base, and the changelog says what to
  do by hand.

## Comments

This codebase carries a lot of prose, on purpose. The rule it is written under:
**keep what the code cannot say about itself, cut what the reader can see.**
Keep the one-line summary, the `@param`/`@return`/`@var` tags, the invariant a
caller can get wrong, the consequence worth knowing, and the reason two things
must agree. Cut version archaeology, alternatives not taken, and restatement of
the next line.

When you change behaviour, change the comment above it in the same edit — the
stale ones found in this repo were all of that kind, a paragraph that outlived
the thing it described.

## Working practices

- There is no test suite. `php -l` is the check. It is not always to hand --
  the sandbox Claude runs commands in on this machine has no PHP -- so lint
  wherever there is one: a container, or the PBX itself.
- Branches are named `amena-<topic>` and merged to `main` through a PR.
- `amena-reduce-comments` exists and is **not merged**; some of what it says it
  fixed is still on `main`.

## Known cruft (not yet fixed, do not be surprised)

- No rate limiting or lockout on token verification.
