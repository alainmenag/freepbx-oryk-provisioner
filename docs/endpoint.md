# The provisioning endpoint

`engine/provisioner.php`, reached through a symlink in the web root, is the
only route a phone can use. It bootstraps FreePBX itself: FreePBX 16/17 sends
every session-less `config.php` request to the login page before a module's
`doConfigPageInit()` ever runs, so a public route cannot go through `config.php`
at all — `requires_auth="false"` governs menu visibility, not anonymous access.

| Request | Serves |
| --- | --- |
| `/provisioner/0004f282e824.cfg` | that profile's `.cfg` resource |
| `/provisioner/0004f282e824` | the same |
| `/provisioner/?mac=0004f282e824` | the same, for a caller with no filename to give |
| `/provisioner/0004f282e824-phone.cfg` | its `phone.cfg` resource |
| `/provisioner/0004f282e824-directory.xml` | its `directory.xml` resource |
| `/provisioner/3111-44500-001.sip.ld` | a File resource of any enabled profile, by name alone — a phone fetching firmware sends no MAC |
| `PUT /provisioner/0004f282e824-boot.log` | stores the body, when that profile has a Log resource answering to the name — its newest 1 MiB, from a whole line; a body over 16 MiB is a 413 |

**Who is asking** is read from the path, then the query string, then an
AudioCodes `User-Agent`. **A MAC in the path always wins over `?mac=`** — which
matters for a filename that carries somebody else's MAC, such as the
`000000000000-directory.xml` a Polycom really does request: it resolves to
`000000000000` and cannot be redirected with `?mac=`.

**What they asked for** is the last segment of the path, verbatim. Which
resource of which profile that names is worked out by the module.

GET and HEAD fetch; PUT sends. A PUT needs a MAC — what a phone sends is
written to disk, so it has to be a phone this module knows — where a fetch does
not, since firmware is asked for by name alone. An `OPTIONS` request is
answered `204` before anything is looked up, and logged nowhere: every answer
carries CORS headers allowing any origin and the `Authorization` header, so a
softphone running in a browser on another site can fetch its configuration.
Anything else is a 404 and a log line.

A MAC that is not associated, a client with no profile, and a filename the
profile does not serve are all 404s. A client that has been switched off, or
whose profile has, is a 403, and so is a request a [ban](bans.md) refuses —
that is asked before anything else, and answered with a bare `Forbidden`. A client that has a token and did not
present it is a 401. With **Settings → Provisioning** Disabled, every request
is a 503 and none is logged.

## A client with no profile

A client with no profile assigned is served the profile **named after the
vendor its `User-Agent` names**, when there is one — name a profile `Polycom`
and every Polycom client without a profile of its own gets it. The name is
matched without regard to case, on every request; nothing is saved to the
client, so assigning it a profile takes over at once. A disabled vendor
profile refuses, as any disabled profile does.

| Vendor | `User-Agent` carries |
| --- | --- |
| Polycom | `Polycom`, or `Poly` as a word |
| Yealink, Grandstream, Cisco, Snom, Fanvil, Htek, Avaya, Obihai, Panasonic, Gigaset, Akuvox | the vendor's name |
| AudioCodes | `AudioCodes` or `AUDC-` |
| Mitel | `Mitel` or `Aastra` |
| WebKit | `WebKit` — a browser, or a softphone built on one |
| PostmanRuntime | `PostmanRuntime` — a request sent from Postman |

They are tried in this order, and the first that matches is the vendor:
Polycom, Yealink, Grandstream, Cisco, Snom, AudioCodes, Fanvil, Htek, Mitel,
Avaya, Obihai, Panasonic, Gigaset, Akuvox, WebKit, PostmanRuntime. So a phone
that also names WebKit is its own vendor. A request with no vendor, or no profile by its name,
is served as a client with no profile — uploaded files by name, and nothing
else.

## Open provisioning

With **Settings → Provisioning** Open, a request for MAC `000000000000` —
`/provisioner/000000000000.cfg`, or any other file under that MAC — is
answered by the HTTP Basic credentials it carries, which are a User Manager
login:

| | |
| --- | --- |
| no credentials | 401, the challenge that makes a phone send them |
| a login that works | that account's default extension |
| a username no account holds | a **sign-up**: a new user, the next free number, as a blank Extension in the Users editor, with an account of that username and password (as *Use Custom Username* gives), and no email — not even a username that is one, so no welcome email goes to an address nobody has confirmed. The SIP secret is generated, not the password. It is put in the **lobby** (below) |
| a username no account holds, using anything but letters, digits, `.` `_` `@` `-`, over 64 characters, only digits, or an IP address | 400 — nothing is made. An existing login is never held to this |
| a username no account holds that is **reserved** (below) | 400 — nothing is made, even from an address with an Allow ban |
| a sign-up over a limit (below) | 429 with `Retry-After` — nothing is made |
| a username held under another password | 401, and a line in FreePBX's security log that the GUI's fail2ban jail bans on |
| no User Manager, or a login whose account is linked to no extension number | 409 |

A login that still works but whose extension or device has been deleted gets
them back on the same number, in the lobby like a new sign-up, and is served
as usual.

The user's client on an internal MAC (`02…`) is then found, or made — enabled,
with no profile and the credentials as its token, so it is served its
vendor's profile (above). The request is answered as that client: `000000000000.cfg` is its `.cfg`, and so on, with
the same 404s and 401s as any other client. A client on a real phone's MAC is
never used.

### The lobby

Every sign-up is put in **Settings → Sign-up Context** (`ORYK_OPEN_CONTEXT`),
`lobby` unless you change it. The lobby is written by the module on Apply
Config. A lobby phone can call:

- other extensions, conferences, and voicemail (`*97`, `*98`);
- the outbound routes marked **Emergency** — and no other route;

and anything else gets "no service": an outside number, a ring group, a queue,
paging, and every other feature code. A lobby phone may place
**Settings → Lobby Calls** (`ORYK_OPEN_CALLS`, 1) calls at once. A call to a
lobby phone that the phone forwards (a SIP 302) is followed in the lobby too,
and a lobby phone cannot transfer, so neither can take a call out to the PSTN.
A lobby account has no UCP login, and one phone at a time registers with its
credentials — a second replaces the first. Its emergency caller id is
**Settings → Lobby Emergency Caller ID** (`ORYK_OPEN_EMERGENCY_CID`) — set it
to a number an emergency operator can call back, and check what your
jurisdiction requires of emergency calls from a multi-line system. A user
leaves the lobby when you change its context in Extensions — see [The lobby](users.md#the-lobby).

An Allow ban on the phone's address does **not** take it out of the lobby.

### Reserved usernames

A sign-up may not take any of these, in any case, as the whole username or as
the part before an `@`:

`admin` `administrator` `root` `sysadmin` `superuser` `system` `sys` `owner`
`manager` `master` `webmaster` `hostmaster` `postmaster` `security` `abuse`
`noc` `it` `reception` `receptionist` `frontdesk` `front-desk` `front.desk`
`front_desk` `operator` `attendant` `switchboard` `main` `office` `lobby`
`support` `help` `helpdesk` `service` `info` `contact` `sales` `billing`
`accounts` `accounting` `finance` `hr` `marketing` `orders` `noreply`
`no-reply` `pbx` `freepbx` `asterisk` `sip` `voip` `ucp` `voicemail` `fax`
`conference` `paging` `intercom` `queue` `ringgroup` `emergency` `oryk`
`provisioner` `provisioning` `guest` `user` `test` `demo` `default` `null`
`anonymous` `unknown` `nobody`

nor `admin`, `sysadmin` or `root` followed by `.`, `_`, `-` or a digit
(`admin2`, `admin.ny`), nor any address at the **Hostname** or **From Domain**
setting's domain or a subdomain of it. Existing logins, and users you make on
the Users tab, are never held to this.

### Limits

Unless an Allow ban decides the request, a sign-up is refused with a 429 once
its address — for IPv6, its /64 — has made **Sign-ups per Minute**
(`ORYK_OPEN_PER_MINUTE`, 1) in the last minute or **Sign-ups per Day**
(`ORYK_OPEN_PER_DAY`, 5) in the last 24 hours, or the whole PBX has made
**Sign-ups per Day, PBX** (`ORYK_OPEN_PER_DAY_TOTAL`, off) — which also puts a
notice on the dashboard. 0 is no limit. A site deploying many phones from one
address gets an Allow ban on that address. Only the address the request
arrived from counts: behind a reverse proxy every sign-up is one address.

Every sign-up, and every refused one, is a line in FreePBX's security log.
Only a wrong password for an existing username is worded as the login failure
FreePBX's fail2ban jail bans for, so a phone retrying after a 429 does not get
its site banned.

### Registering before Apply Config

A sign-up never reloads anything. It raises Apply Config, and until someone
applies, the new extension reaches Asterisk through the **Realtime bridge**:
its endpoint, auth and AOR are written to three tables of this module's that
Asterisk is told to read after its config files, so the phone can register at
once. A minute job takes the rows out once Apply Config has written the
extension; a sign-up still waiting a day later puts a notice on the dashboard.
Install sets the bridge up and says so — it needs Asterisk restarted once, and
`res_odbc`, `res_config_odbc` and `res_sorcery_realtime` loaded. Without it, a
sign-up works from the next Apply Config.

A Polycom asks for `000000000000-directory.xml` and, without a `<mac>.cfg`,
`000000000000.cfg`. While open provisioning is on, those requests are open
provisioning's: without credentials they are a 401, not a 404.

## How a filename is matched

A resource's name is itself a template, and it matches a request two ways:

| Written as | Matches | Because |
| --- | --- | --- |
| `{{device.mac}}-phone.cfg` | `0004f282e824-phone.cfg` | the name is rendered with this client's values and compared to the request |
| `phone.cfg` | `0004f282e824-phone.cfg` | the name is compared to the request with this client's MAC taken off the front |

Both are the same string in the same column — the second is only what the first
becomes when it has no placeholders in it. A name written out in full wins over
one that matches only the tail. Matching ignores case throughout, and the MAC
is stripped in whichever separator style the phone used (`0004f282e824`,
`00:04:f2:82:e8:24`, `0004.f282.e824`).

**Name the main config `.cfg`.** Written that way it is matched with the MAC
taken off the front, so it answers whichever separator style the phone asks in.
`{{device.mac}}.cfg` works too, but it renders without separators and so only
answers a phone that asks that way. A profile with neither serves nothing for
`<mac>.cfg`.

Names are unique per profile, not globally — two profiles both serving a
`{{device.mac}}-phone.cfg` is the normal case.

**Content type** is taken from the extension: `.xml` is served as `text/xml`,
`.json` as `application/json`, `.cfg`/`.conf`/`.ini`/`.txt`/`.log` as
`text/plain`. Anything else is `text/plain` for a template or a log and
`application/octet-stream` for an uploaded file — sending a firmware image as
text is how it arrives corrupted.

## Asking for JSON, XML or plain text

A caller can ask for any template or uploaded config in another format with
the `Accept` header:

```
curl -H 'Accept: application/json' https://pbx/provisioner/0004f282e824.cfg
curl -H 'Accept: text/xml'         https://pbx/provisioner/0004f282e824.cfg
curl -H 'Accept: text/plain'       https://pbx/provisioner/0004f282e824-phone.xml
```

| Accept | Answer |
| --- | --- |
| missing, or any wildcard anywhere (`*/*`, `text/*`) | as stored |
| `application/json` | a JSON object |
| `application/xml`, `text/xml` | XML, sent with the type asked for |
| `text/plain` | `key=value` lines |
| only other types | as stored |

A config already in the format asked for is sent exactly as stored. One that
cannot be rewritten — firmware, anything over 2 MB, or text that is not JSON,
XML or `key=value` — is a 406. Rewriting drops comments, and anything converted
out of XML or plain text comes back as strings. A phone that sends no `Accept`
header, or a wildcard, is never affected.

