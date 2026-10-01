# Installing

Install the module as usual (`fwconsole ma install oryk_provisioner`, or upload
it in Module Admin).

Each tagged version is published as `oryk_provisioner-<version>.zip` on the
repository's Releases page, ready to upload in Module Admin — see
[Releasing](releasing.md) for how one is cut.

`install()` registers the module's settings in Advanced Settings (keeping any
value already there) and adds indexes on `devices.id`, `devices.user` and
`userman_users.email` for the Users tab. Back up the FreePBX database before
installing or upgrading. Uninstalling removes the module's settings with the
module, as FreePBX does with every module's settings.

`install()` also symlinks the module's `engine/` directory into the web root:

```
/var/www/html/provisioner -> .../admin/modules/oryk_provisioner/engine
```

which is what gives phones a short URL instead of a path under `/admin/` that a
hardened site may not serve anonymously at all. Apache has to be willing to
follow it — `Options FollowSymLinks` on the web root, the FreePBX default.

Nothing about the link fails the install. If the web root is not writable, or
something else already lives at `/provisioner`, you get a message on the console
and the endpoint stays reachable at its real path:

```
http://<pbx>/provisioner/<mac>
```

`engine/.htaccess` rewrites everything under the directory to `provisioner.php`,
so the filename a phone asks for arrives as the request path. `uninstall()`
removes the symlink — and only if it still resolves to this module's engine.

The **Bans** tab needs one more step, as root, that a module install from the
GUI cannot do — see [Installing fail2ban access](fail2ban.md#installing-fail2ban-access). Uninstalling from the
GUI leaves the fail2ban helper and its sudo rule in place: run
[`--remove`](fail2ban.md#uninstalling-fail2ban-access) first.

## Coming from Oryk Connect

This module replaces `oryk_connect` for **Extension/User** devices. Nothing
moves: users, extensions, accounts and mailboxes are Core's, so every one Connect
made is already on the Users tab. Both can be installed at once; they write the
same endpoint file under the same lock.

Before removing Connect, check it has nothing left only it can manage:

```sql
SELECT id, description FROM devices WHERE tech = 'rtsp';
SELECT s.data AS kind, COUNT(*) FROM devices d
  JOIN sip s ON s.id = d.id AND s.keyword = 'kind' GROUP BY s.data;
```

**RTSP feeds need Connect's driver** — do not remove Connect while any
`tech = 'rtsp'` device exists. Handsets and softphones are plain PJSIP devices
and keep working; nothing in Oryk edits them afterwards (a handset's place here
is a client).

Then:

1. `fwconsole ma upgrade oryk_provisioner` — this takes the From Domain setting
   over.
2. `fwconsole setting ORYK_FROM_DOMAIN` — the value you had in Connect.
3. Save one user from the Users tab, and check its section in
   `pjsip.endpoint_custom_post.conf` and that nothing else in the file changed.
4. Remove Connect (`rm -rf /var/www/html/admin/modules/oryk_connect`, since
   Connect is marked non-uninstallable), then `fwconsole reload`.
5. `fwconsole setting ORYK_FROM_DOMAIN` again. If it is empty, set it again.

Bookmarks to `?display=oryk_connect` become `?display=oryk_provisioner&tab=users`.
