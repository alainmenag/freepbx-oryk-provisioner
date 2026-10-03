# Bans (fail2ban)

The **Bans** tab lists what fail2ban has banned, in every jail — on a FreePBX
box usually `asterisk` (SIP) and `sshd`. Each row is one address in one jail,
with when it was banned, when it expires, and the **client** whose Public IP it
is, when it is one: a site whose phones keep failing to register is the usual
reason anyone opens this tab. **Unban** lifts a ban now. **Add Ban** bans one
address in one jail for that jail's own bantime, as though fail2ban had banned
it. A ban cannot be edited; its page shows it, with Unban and Close.

The tab can be switched off — and back on — with **Fail2ban Bans** on the
[Settings](admin.md#settings) tab (`ORYK_FAIL2BAN`, also in Advanced Settings). Off, the
tab is not drawn, a ban's page goes back to the module page, the AJAX commands
refuse, and nothing calls sudo. It is on by default; a PBX that has the new
files but has not had `fwconsole ma install` run yet treats it as on.

Adding a ban refuses the address you are connected from, loopback, and the
PBX's own addresses. One written on a client as its Public IP is allowed after
a warning, since it blocks every phone at that site. One address at a time; no
ranges.

## Installing fail2ban access

fail2ban only answers root, and the GUI runs as the web user, so the module
reaches it through a small helper that sudo lets the web user run. Until that
is in place the Bans tab shows what is missing and the command to run instead of
a table.

1. fail2ban has to be installed and running:

   ```bash
   sudo apt install fail2ban
   sudo systemctl enable --now fail2ban
   sudo fail2ban-client status          # lists the jails, e.g. asterisk, sshd
   ```

2. Put your own address in `ignoreip` in `/etc/fail2ban/jail.local`, so a wrong
   ban on `sshd` cannot lock you out, and `sudo systemctl reload fail2ban`.

3. Once on each PBX, as root:

   ```bash
   sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup
   ```

4. Open the Bans tab, or press **Check again** on it. The list appears.

The script checks fail2ban, Python, sudo and the web user
(`AMPASTERISKWEBUSER`, normally `asterisk`), then installs two things and
nothing else:

| | |
| --- | --- |
| `/usr/local/sbin/oryk-fail2ban` | a root-owned copy of the module's `bin/oryk-fail2ban`. It can list jails and bans and ban or unban one address in one jail, and refuses anything else |
| `/etc/sudoers.d/oryk_provisioner` | `asterisk ALL=(root) NOPASSWD: /usr/local/sbin/oryk-fail2ban` — that one file, and nothing else, as root. Checked with `visudo` before it is used |

It then runs the helper as the web user and prints **OK** with the jails it
found:

```
  existing sudo configuration                  ok
  installing /etc/sudoers.d/oryk_provisioner   ok
  ...
  the web user can reach fail2ban              ok (asterisk, sshd)

OK. Reload the Bans tab.
```

It is safe to run again, and has to be run again after a module upgrade that
changes the helper — the tab says so (*not the one this version of the module
ships*). `fwconsole ma install` or `upgrade`, run as root, runs the script for
you and prints what it said; from Module Admin in the GUI it cannot (no root),
and the install message gives the command instead.

If it stops, it says at which step and why, and changes nothing after it:

| stops at | what to do |
| --- | --- |
| *fail2ban installed* / *fail2ban running* | `sudo apt install fail2ban`, `sudo systemctl enable --now fail2ban`, run it again |
| *existing sudo configuration* | another file in `/etc/sudoers.d` fails `visudo -c`, and sudo takes no new rule until it passes. A wrong mode or owner (`bad permissions, should be mode 0440`) is fixed by the script — the same as `sudo chmod 0440 /etc/sudoers.d/<file>` — and listed as `fixed:`. Anything else is printed with its file and line: correct it with `sudo visudo -f /etc/sudoers.d/<file>`, check with `sudo visudo -c`, run it again |
| *the web user can reach fail2ban* | the line printed is what sudo said; `--check` shows every check at once |

## Checking fail2ban access

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup --check
```

Runs every check and changes nothing: fail2ban, the whole sudo configuration,
the installed helper (present, owned by root, the version this module ships),
the sudo rule, and a call as the web user. Ends with **OK**, or with how many
problems there are — running the script without `--check` fixes them.

By hand, as the web user would:

```bash
sudo -u asterisk sudo -n /usr/local/sbin/oryk-fail2ban check
sudo -u asterisk sudo -n /usr/local/sbin/oryk-fail2ban list asterisk
```

## Uninstalling fail2ban access

```bash
sudo bash /var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup --remove
```

Deletes `/usr/local/sbin/oryk-fail2ban` and `/etc/sudoers.d/oryk_provisioner`,
and nothing else: fail2ban, its jails and every ban stay exactly as they are,
and the web user can no longer reach it. The Bans tab goes back to the setup
instructions. To hide the tab as well, set **Fail2ban Bans** to No on the
Settings tab; switching it off alone leaves the helper and sudo rule installed,
unused. `fwconsole ma uninstall oryk_provisioner`, run as root, does the
same; uninstalled from the GUI it cannot, so run `--remove` first — the script
is inside the module directory, and goes with it.

Any file the script fixed the mode or owner of (`fixed:` in its output) is left
fixed: that is the mode sudo expects, and `--remove` does not put it back.

## Testing the setup from scratch

Keep a root shell (`sudo -i`) open in another window while you do this.

```bash
S=/var/www/html/admin/modules/oryk_provisioner/bin/oryk-fail2ban-setup

sudo bash $S --remove          # the tab: helper not installed
sudo bash $S --check           # every missing piece listed, nothing changed
sudo bash $S                   # installed; ends with OK
sudo bash $S --check           # every line ok
```

Then from the tab: **Add Ban** `203.0.113.7` in `asterisk` (a documentation
address, so nobody real), see it with `sudo fail2ban-client status asterisk`,
**Unban** it, and check it has gone. Banning the address you are connected from
is refused.

Only the Bans section itself asks fail2ban, so only opening it writes a sudo
line to the auth log.
