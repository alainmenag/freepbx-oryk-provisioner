# Releasing

A release is a git tag. Pushing one runs `.github/workflows/release.yml`, which
builds `oryk_provisioner-<version>.zip` and attaches it to a GitHub Release of
the same name — the zip operators upload in Module Admin.

**The tag must equal `<version>` in `module.xml`.** A leading `v` is allowed
(`v1.1.1` matches `1.1.1`), anything else that differs fails the build and
nothing is published. The version FreePBX shows for an installed module is the
one in `module.xml`, so the check is what keeps a release's name and the module
inside it from disagreeing.

## Cutting a release

1. On your branch, bump `<version>` in `module.xml` and add a `*<version>*`
   entry at the top of `<changelog>`.
2. Merge the PR to `main`.
3. Tag the merged commit on `main` and push the tag:

   ```bash
   git checkout main
   git pull
   grep -m1 '<version>' module.xml      # must say the number you are about to tag
   git tag 1.1.1
   git push origin 1.1.1
   ```

4. Watch the **Release** run under the repository's *Actions* tab. When it is
   green the zip is on the Releases page.

To take the number from `module.xml` rather than typing it, so the two cannot
differ:

```bash
v=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' module.xml | head -1)
git tag "$v" && git push origin "$v"
```

## What the build does

1. Strips a leading `v` from the tag and compares it with the first
   `<version>` in `module.xml` **at the tagged commit**. A mismatch stops here.
2. Runs `php -l` over every PHP file.
3. Builds the zip with `git archive` from the tagged commit, with everything
   under an `oryk_provisioner/` directory. Paths marked `export-ignore` in
   `.gitattributes` — `.github`, `tests`, `CLAUDE.md` and the git dotfiles — are
   left out.
4. Creates the GitHub Release, titled with the tag, with notes generated from
   the PRs merged since the last one.

Uncommitted or unpushed work never reaches the zip: it is built from the tag,
not from your working tree.

## When a tag is wrong

A tag that does not match, or that points at the wrong commit, published
nothing if its build failed. Delete it locally and on GitHub, fix the cause,
and tag again:

```bash
git tag -d 1.1.1
git push origin --delete 1.1.1
```

If the build succeeded, the Release exists. Do not move a published tag — a
PBX may already have that zip installed. Bump the version and release the next
number instead.
