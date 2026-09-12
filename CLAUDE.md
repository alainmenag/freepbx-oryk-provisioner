# CLAUDE.md

Guidance for Claude Code working in this repository.

## Hard rules

- `.vscode/sftp.json` points a save-watcher at a live PBX
  (`/var/www/html/admin/modules/oryk_provisioner`). Assume anything written
  here can reach that box. `.vscode/` is gitignored; do not add files to it.

## Where to look first

| you want | read |
| --- | --- |
| how the system fits together, the schema, why a decision was made | `ARCHITECTURE.md` |
| what it does, from an operator's side | `README.md` |
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
- A new AJAX command must be named in **both** `ajaxRequest()` and
  `ajaxHandler()`.
- No view contains a `<form>`; fields are read by id and posted over AJAX.
- Tab badges come from `Counts`, never from the table they label.
- `Schema` steps are additive and ask `information_schema`, never a dbversion.
  `addResourceTypeColumn()` must stay after `addResourceFileColumns()`.
- PHP and views are indented with **tabs**. Operator-facing strings go through
  `_()`.

## Working practices

- There is no test suite. `php -l` is the check, and it is not always to hand --
  the sandbox Claude runs commands in on this machine has no PHP -- so lint
  wherever there is one: a container, or the PBX itself.
- Branches are named `amena-<topic>` and merged to `main` through a PR.
