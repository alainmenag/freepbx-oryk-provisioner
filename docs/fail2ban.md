# Syncing with fail2ban

Bans that name an **IP address and nothing else** are kept in step with
fail2ban, both ways, every minute — and at once when you save or delete one on
the Bans tab. Bans on a client, user, MAC or profile stay the provisioner's
own: fail2ban only knows addresses.

| On the Bans tab | In fail2ban |
| --- | --- |
| **Banned** | banned in the `asterisk` jail |
| **Deny** | banned in the `deny` jail — permanent, every port |
| **Allow** | added to every jail's ignore list, and unbanned wherever it is banned |

And the other way: every address fail2ban bans, in any jail, appears on the
Bans tab with **Source** `fail2ban` and the **Jail** it came from — Banned until
fail2ban's own expiry, or Deny when that jail bans permanently (`recidive`, say).
When fail2ban lets it go, the ban shows as expired. Nothing is deleted.

A ban's page says where it is in fail2ban, and when that was last confirmed.

## What the sync never does

- **Change a ban you made.** If fail2ban bans an address you have a ban for that
  is still in force, your ban stays as it is. An expired one is revived, keeping
  its source.
- **Follow a fail2ban ban you've saved.** A ban fail2ban made is followed by the
  sync — refreshed, and expired when fail2ban lets it go — until you save it on
  its page. Then it's yours: change it to Deny and it moves into the `deny` jail
  and stays; its Source still says `fail2ban`.
- **Lift what it didn't put there.** A ban fail2ban made on its own, or an
  address you listed in `jail.local`'s `ignoreip`, is left alone.
- **Push loopback or the PBX's own addresses.** Loopback can't be banned at all.
- **Act on a guess.** If fail2ban can't be read (stopped, restarting), the
  minute does nothing — rather than taking "no answer" as "no bans".

## The two jails

- `asterisk` is FreePBX's own. A Banned ban pushed there lasts that jail's
  bantime (fail2ban can't be given a time per address); a longer one is banned
  again on the next minute after fail2ban lifts it.
- `deny` is written by setup: permanent, every port, and holds your Deny bans
  and nothing else — each minute it is made to match the Bans tab exactly.
  **A Deny on your own address locks you out of the PBX**, the GUI included.

## Setting it up

Once per PBX, as root:

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup
```

`fwconsole ma install` or `upgrade`, run as root, runs it for you. Until it has
run, the Bans tab says what is missing and shows the command. It needs fail2ban
0.11 or later, running, with an `asterisk` jail.

It installs three things and nothing else:

| | |
| --- | --- |
| `/usr/local/sbin/oryk-fail2ban` | a root-owned copy of the module's `bin/oryk-fail2ban`. It can list, ban in `asterisk` or `deny`, unban, and add or remove an ignore entry — one address at a time, never loopback — and refuses anything else |
| `/etc/sudoers.d/oryk_provisioner` | lets the FreePBX web user run that one file as root. Checked with `visudo` before it is used |
| `/etc/fail2ban/jail.d/deny.conf`, `filter.d/deny.conf` | the `deny` jail. Setup refuses to write over a `[deny]` jail it didn't write |

It ends by running the helper as the web user and printing **OK** with the jails
it found. Safe to run again, and needed again after an upgrade that changes the
helper (the tab says *out of date*). `--check` reports every piece and changes
nothing.

The minute itself is FreePBX's scheduler running `bin/oryk-fail2ban-sync`,
added by the module install. It can be run by hand (`php bin/oryk-fail2ban-sync`)
and prints what it did.

## Pausing it

**Settings → Fail2ban Sync** (`ORYK_FAIL2BAN_SYNC`) set to No pauses it: nothing
is read from or written to fail2ban, saves on the Bans tab stay in the
provisioner, and nothing already in fail2ban is undone.

## Removing it

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup --remove
```

Deletes the helper, the sudo rule and the `deny` jail (and so the bans in it),
and reloads fail2ban. The Bans tab keeps every ban. `fwconsole ma uninstall`, run
as root, does the same; uninstalled from the GUI it cannot, so run `--remove`
first — the script goes with the module.
