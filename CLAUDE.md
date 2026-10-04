# CLAUDE.md

Guidance for Claude Code working in this repository.

## Hard rules

- `.vscode/sftp.json` points a save-watcher at pbx-east-1
  (`/var/www/html/admin/modules/oryk_provisioner`). It is a **dev box** and
  the auto-upload is wanted: anything written here, including a branch
  switch, reaches it. Work on an `amena-<topic>` branch or on the current
  branch directly, as asked. `.vscode/` is gitignored; do not add files to it.
- Scratch -- tarballs, patches, helper scripts -- goes in `Claude outputs/`
  (gitignored, and in the watcher's ignore list), never anywhere else in the
  tree.

## Where to look first

| you want | read |
| --- | --- |
| how the system fits together, the schema, why a decision was made | `ARCHITECTURE.md` |
| what it does, from an operator's side | `README.md`, then `docs/` |
| how a release is tagged | `docs/releasing.md` |
| what one function guarantees or gets wrong | the docblock on it |

**Read `ARCHITECTURE.md` before changing behaviour.** It carries the request
flow, the matching rules, the column-by-column schema rationale, and the
conventions that hold across every file. Do not reconstruct that by reading
`src/` -- and do not copy it back into `src/` either.

A FreePBX 16/17 module (`rawname` `oryk_provisioner`, BMO class
`Oryk_provisioner`) that provisions VoIP phones. It is Stage 1 of a larger
design; `ARCHITECTURE.md` says what is not built yet.

## Comments

The source is documented to one rule:

> **Keep what the code cannot say about itself. Cut what the reader can see.
> Put the big picture in `ARCHITECTURE.md`, not in twenty files.**

Keep, in a file:

- the one-line summary on every class, method and property;
- `@param` / `@return` / `@var` on everything;
- the invariant a caller can get wrong -- "the state is sent, not toggled",
  "this table name is interpolated and may never come from a request";
- the consequence worth knowing -- an uploaded file is fetchable by anyone who
  reaches the endpoint and knows its name;
- the reason two expressions must agree -- `logFile()` is the one path both the
  write and the read go through.

Cut, or never write:

- restatement of what the next line plainly does (`// Loop through the users`);
- version archaeology -- "until 1.0.14 there was nowhere for those to go" --
  unless the bug it left is still the thing being explained;
- alternatives not taken, where the taken one is now obvious;
- second and third paragraphs restating the first in other words;
- placement rationale ("this lives here rather than there") beyond one clause;
- anything an `ARCHITECTURE.md` section already says. Link to it instead.

A `/** @var Clients */` is one line, not three.

When you change behaviour, change the comment above it **in the same edit**.
Every stale comment found in this repo was a paragraph that outlived the thing
it described. If a change makes an `ARCHITECTURE.md` section wrong, fix that in
the same edit too.

Comment-only passes over `src/` are made mechanically -- blocks are extracted
with their line ranges, replacements are spliced back by index with a drift
check, and the result is verified by stripping comments from both revisions and
diffing. Never rewrite a file wholesale to reword its comments.

## Conventions

`ARCHITECTURE.md` has these in full. The ones most often got wrong:

- A table or column name written into SQL is interpolated, so it may never come
  from a request. Sort columns are whitelisted and mapped; everything else is
  bound.
- A new AJAX command is one entry in `Oryk_provisioner::commands()`; nothing
  else lists them.
- No view contains a `<form>`; fields are read by id and posted over AJAX.
- CSS goes in `assets/oryk_provisioner.css`, not in a `<style>` in a view.
- JavaScript goes in `assets/scripts/<view>.js`. A view writes only the
  `oryk...` constants PHP has to fill in, then `<?php echo $script('<view>'); ?>`.
- A field's help is a `fpbx-help-icon` with `data-for="<id>"` after the label
  and one `fpbx-help-block` with `id="<id>-help"`; without the pair FreePBX
  never shows it.
- The only badges are the navigator dropdown titles' `count`, from `Navigator`;
  tabs and the section bar carry none.
- `Schema` steps are additive and ask `information_schema`, never a dbversion.
  `addResourceTypeColumn()` must stay after `addResourceFileColumns()`.
- `bin/oryk-fail2ban` is the root privilege boundary: it re-checks every
  argument, and `HELPER_VERSION` is bumped whenever it changes.
- `AsteriskConfig`'s lock file stays named `oryk-connect-…` while any PBX may
  still have `oryk_connect` installed: both write the same file.
- PHP and views are indented with **tabs**. Operator-facing strings go through
  `_()`.

## Working practices

- **`bin/check` is the one check**: `php -l` on every PHP file, the root
  helpers' syntax, `node --check` on `assets/scripts/`, `tests/smoke.php`
  (stubs, nothing installed), and PHPStan when it is installed. CI
  (`.github/workflows/ci.yml`) runs the same script on PHP 7.4 and 8.2 for
  every push.
- Alain's Mac has PHP (Homebrew) and runs `bin/check` itself. Claude's shell
  on that Mac is a Linux VM without PHP: tar the working tree into
  `Claude outputs/`, stage it, and run `bin/check` in the cloud workspace
  (PHPStan: `curl -sSLo phpstan.phar
  https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar`;
  `bin/check` picks up `./phpstan.phar`).
- `.claude/settings.json` lints every file Claude Code writes (php -l,
  node --check) and hands an error straight back.
- PHPStan runs at level 5 against `phpstan-baseline.neon`. Never add to the
  baseline; when a line in it is fixed, regenerate it
  (`phpstan analyse --generate-baseline`).
- The code must run on PHP 7.4 (FreePBX 16): no `mixed`, union or nullsafe
  syntax, no `str_contains()`, `match` or named arguments.
- Branches are named `amena-<topic>` and merged to `main` through a PR.
  Claude's VM has no GitHub credentials: commit there, and Alain pushes.

## Done means

1. `bin/check` passes.
2. A changed behaviour has a test in `tests/smoke.php` when stubs can reach it.
3. The comment above anything changed, and any `ARCHITECTURE.md` section it
   makes wrong, are fixed in the same commit.
4. A change an operator would notice gets a line in `module.xml`'s
   `<changelog>` with the next version bump (`docs/releasing.md`).
