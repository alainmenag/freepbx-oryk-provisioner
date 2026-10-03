# Oryk Provisioner

Template-driven endpoint provisioning for FreePBX 16 and 17.

A phone asks the PBX for a configuration file by its MAC address. The module
works out which profile that MAC belongs to, finds the file of that profile the
phone asked for, fills in its placeholders from FreePBX, and serves it.
Vendor-specific syntax stays in templates you write; the module holds the data
and the rendering.

Alongside provisioning it manages **users** (an extension, its User Manager
account, mailbox and SIP device as one number), **bans** — refusing, or
explicitly allowing, an address, MAC, user, client or profile, with IP bans
kept in step with fail2ban — and logs every request a phone makes.

> [!WARNING]
> A client with no token is served to anyone who can reach the URL and knows
> its MAC address — configuration, SIP secret included. Read
> [Security](docs/security.md) before putting this anywhere public.

## Getting started

### Install

```bash
cd /var/www/html/admin/modules
git clone https://github.com/alainmenag/freepbx-oryk-provisioner.git oryk_provisioner
sudo fwconsole chown
sudo fwconsole ma install oryk_provisioner
```

Or download the latest zip from the
[Releases](https://github.com/alainmenag/freepbx-oryk-provisioner/releases)
page and upload it in *Admin → Module Admin*.

Syncing IP bans with fail2ban needs one more step as root — see
[Syncing with fail2ban](docs/fail2ban.md#setting-it-up). `fwconsole ma install`
run as root does it for you.

### Provision a phone

1. **Make a profile.** *Oryk → Provisioner → Profiles → Add Profile*, name it
   (`Polycom VVX 500`) and save.
2. **Add the main config.** On the profile's **Resources** tab, *Add
   Resource* with filename `{{device.mac}}.cfg` and a template:

   ```
   reg.1.address="{{extension.number}}"
   reg.1.auth.userId="{{device.username}}"
   reg.1.auth.password="{{device.secret}}"
   reg.1.label="{{extension.name}}"
   reg.1.server.1.address="{{server.host}}"
   reg.1.server.1.port="{{server.port}}"
   ```

   Add a resource for each other file the phone fetches — `phone.cfg`,
   `directory.xml`, firmware.
3. **Add a client.** *Clients → Add Client*: the MAC, the FreePBX device it
   stands for, the profile.
4. **Check it.** The client's **Resources** tab lists every file it will ask
   for, each with **Render**, which opens it as the phone will get it, and **Open**,
   which fetches it from the endpoint as the phone does.
5. **Point the phone at** `http://<pbx>/provisioner/`. Most phones append their
   own MAC and filename.

## Documentation

Everything else is in [`docs/`](docs/README.md): the endpoint and filename
matching, every template placeholder, the admin pages, users, bans and fail2ban,
security, and [releasing a version](docs/releasing.md). How the module is built
is in [`ARCHITECTURE.md`](ARCHITECTURE.md).
