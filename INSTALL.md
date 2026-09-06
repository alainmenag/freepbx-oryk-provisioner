# Oryk Provisioner - installation and implementation notes

Companion to `README.md`, which describes the provisioning model. This file
covers how the module is installed, how the pieces fit together, and the
decisions worth knowing before it goes to production.

---

## Install

Copy the module into the FreePBX modules directory and install it:

```bash
rsync -a oryk_provisioner/ root@pbx:/var/www/html/admin/modules/oryk_provisioner/
ssh root@pbx 'fwconsole ma install oryk_provisioner && fwconsole chown && fwconsole reload'
```

`fwconsole ma install` runs `install.php`, which:

* creates the module tables (`oryk_provisioner_templates`,
  `_template_outputs`, `_devices`, `_logs`, `_settings`),
* applies any pending migrations (guarded `ALTER TABLE`s, safe to re-run),
* loads the bundled templates from `seed/*.json` without touching templates that
  already exist.

`fwconsole chown` is what symlinks `assets/js` and `assets/css` into
`/admin/assets/oryk_provisioner/`, so run it after any asset change.

Uninstalling drops the module tables and removes the friendly URL shim.

---

## Endpoints

| URL | Auth | Notes |
| --- | --- | --- |
| `/admin/config.php?display=oryk_provisioner&token={token}&filename={file}` | token | The URL documented in the README |
| `/admin/modules/oryk_provisioner/provision.php?token={token}&filename={file}` | token | Direct endpoint, no FreePBX page stack |
| `/admin/modules/oryk_provisioner/provision.php/{token}/{file}` | token | Same, via `PATH_INFO` |
| `/provisioner/{token}/{file}` | token | Optional friendly URL, installed from Settings |
| `/admin/config.php?display=oryk_provisioner` | FreePBX admin session | Admin interface |
| `POST /admin/api/api/gql` | FreePBX API OAuth | GraphQL management API |

All of them run the same `Provisioning\Engine`, so a preview in the admin
interface and a real device request cannot drift apart.

Responses follow the README: `200` with the output's content type, `404` for an
invalid token, a disabled device or an unknown filename (identical in all three
cases), `500` when rendering fails, with the detail logged rather than returned.

### Why `requires_auth="false"` in module.xml

The README's canonical provisioning URL goes through `config.php`, which
normally requires an admin session. The menu item therefore declares
`requires_auth="false"`, and the module does its own check in
`Oryk_provisioner::doConfigPageInit()`:

* request carries `token` **and** `filename` -> the provisioning engine answers
  and the process exits before any FreePBX markup is produced;
* anything else -> a FreePBX admin session is required, otherwise the request is
  redirected to the login page.

AJAX commands are independently protected (`$setting['authenticate'] = true`,
`allowremote = false`), so admin data is never reachable without a session.

If you would rather keep `config.php` fully authenticated, turn off
**Settings -> FreePBX provisioning URL**; devices then use `provision.php`, and
the config.php route stops resolving. Setting `requires_auth="true"` in
`module.xml` has the same effect at the framework level.

---

## Layout

```
oryk_provisioner/
├── Oryk_provisioner.class.php    BMO adapter: lifecycle, page, AJAX (thin)
├── page.oryk_provisioner.php     Page entry point
├── provision.php                 Device provisioning endpoint
├── install.php / uninstall.php   Schema + bundled templates
├── Api/Gql/Oryk_provisioner.php  GraphQL queries and mutations
├── lib/                          The provisioning library (namespace Oryk\Provisioner)
│   ├── Provisioner.php           Service container - one wiring of the engine
│   ├── Settings.php              Key/value module settings
│   ├── Admin/                    AjaxController, View, FriendlyUrlInstaller
│   ├── Database/                 Connection, Schema (create + migrate)
│   ├── Exception/                NotFound, Render, Validation
│   ├── Freepbx/Facade.php        Every call into FreePBX, degrades gracefully
│   ├── Model/                    Device, Template, Output, ParameterSchema
│   ├── Provisioning/             Engine, ParameterResolver, Context, UrlBuilder,
│   │                             RenderedFile, HttpEndpoint
│   ├── Repository/               Device, Template, Log persistence
│   ├── Seed/TemplateSeeder.php   Loads seed/*.json
│   ├── Support/                  Str, Arr, Json, Token
│   └── Template/                 Parser, Renderer, Filters, Escaper
├── views/                        Server rendered markup, injected over AJAX
├── assets/js|css/                Admin interface behaviour and styling
├── seed/                         Bundled templates (softphone, Yealink, Poly, Grandstream)
└── tests/                        CLI test suite (SQLite, no FreePBX needed)
```

Nothing in `lib/` depends on FreePBX except `Freepbx\Facade`, which is why the
suite in `tests/` can run anywhere:

```bash
php tests/run.php
```

---

## Template syntax

```text
{{ sip.username }}                 escaped for the output's content type
{{{ sip.username }}}               raw, never escaped
{{ sip.port | default:5060 }}      filters, with arguments
{{ device.mac | mac:colon }}       00:15:65:AA:BB:CC
{{#if sip.secure}}2{{else}}0{{/if}}
{{#unless sip.outbound_proxy}} ... {{/unless}}
{{#each directory}}{{name}} {{extension}} {{@number}}{{/each}}
{{! a comment }}
```

Escaping follows the output's content type: JSON outputs get JSON-safe values,
XML/HTML outputs get XML-safe values, and key/value formats have newlines
flattened so a value cannot forge an extra setting. Triple braces opt out.

Filters: `upper lower ucfirst trim default json xml url base64 md5 sha1 mac
replace substr pad int yesno onoff bool date prefix suffix slug length raw`.
Add your own without touching the engine:

```php
\Oryk\Provisioner\Template\Filters::register('vendorflag', function ($value, array $args) {
    return $value === 'tls' ? '2' : '0';
});
```

---

## Parameter resolution

Lowest priority first:

```text
module settings -> schema defaults -> template defaults
   -> FreePBX / extension values -> device fields -> device parameters
```

The resolved `Context` keeps the source of every value, which is what the
preview's **Source** column shows, and which parameters are secret, which is
what masks them in the preview and keeps them out of the log.

Values FreePBX contributes: `sip.username`, `sip.auth_username`, `sip.password`,
`sip.tech`, `user.extension`, `user.display_name`, `user.email`, `server.port`,
plus `directory` (the extension list) when enabled.

---

## Security notes

* Tokens are `random_bytes` hex, contain nothing derived from the device, and
  are compared with `hash_equals`. Regenerating one invalidates the old URL at
  once; disabling a device stops provisioning without deleting anything.
* Requested filenames are sanitised (`basename` + character allowlist) before
  they reach the engine, so traversal attempts return the same 404 as any other
  unknown file.
* The log stores metadata only - never rendered configuration, never parameter
  values. Rendering failures are logged through the FreePBX logger and answered
  with a bare `500`.
* GraphQL only returns a device's token and provisioning URL to callers holding
  the `write:provisioner` scope.
* Optional hardening in Settings: require HTTPS, restrict source networks
  (IP/CIDR list), shorten log retention.
