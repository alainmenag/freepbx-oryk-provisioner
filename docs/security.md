# Security

Know what this is before you expose it:

- **A client with no token is keyed on MAC address alone.** Anyone who can
  reach the URL and knows — or guesses — a MAC gets that client's rendered
  configuration, including `device.secret` if the template emits it. Giving the
  client a token closes that: the endpoint then answers it nothing without HTTP
  Basic credentials that verify against the stored hash. A new client gets one
  generated if you leave the box empty, but an existing client's can be
  emptied, so a client without one is still possible —
  restrict who can reach `/provisioner/` at the network layer, and prefer
  HTTPS, since Basic credentials over plain http are credentials in the clear.
- **A token is not rate limited and there is no lockout.** A wrong one costs
  the caller a single bcrypt verification over an endpoint anyone can reach.
- **An uploaded File resource is served to a caller with no client behind it
  at all**, matched by name across every enabled profile — which is what lets a
  phone fetch firmware before anybody has written its client, and also means
  anyone who reaches the endpoint and knows the name can fetch it.
- **Failures are not uniform.** A 404 or 403 says which kind of failure it was
  ("… is not associated with anything", "… has no profile assigned",
  "… is disabled", "The … profile is disabled"), so a caller probing MACs can
  tell a known one from an unknown one.
- **Secrets are not masked anywhere in the UI.** Open, Render and Download give the real
  rendered file, secret included.
- **Only admins given this module can use it.** It is not `access="all"`: an
  administrator limited to other modules in *Admin → Administrators* cannot
  open it or call its commands.
- **A phone's log is capped.** The newest 1 MiB of what it PUT is kept; a body
  over 16 MiB is refused. Anyone who can PUT for a client can still overwrite
  that client's log.
- **Open provisioning creates PBX users from an unauthenticated request.**
  With **Provisioning** Open, anyone who reaches the endpoint, from any address,
  can create an extension, account and mailbox per username they invent, each
  costing a full reload; nothing limits the rate. A wrong
  password for an existing username is written to FreePBX's security log, which
  is what FreePBX's own GUI jail watches; new usernames are not failures and
  are not banned.
- **Deleting a user deletes its call history and recordings** — see
  [Deleting](users.md#deleting).
- The provisioning log records metadata only — MAC, file, profile — never the
  rendered body or any parameter value.
- Sortable columns are whitelisted and mapped to SQL names before being written
  into a statement; everything else is bound.
- **Syncing with fail2ban is root access, narrowed.** The sudo rule lets the
  web user run one root-owned file, which checks every argument itself and can
  only list, ban in `banned` or `deny`, unban, and add or remove an ignore
  entry — and only in `banned`, `deny` and the PBX jails root lists in
  `/etc/oryk-fail2ban.conf`; `sshd` and every other jail are out of its reach.
  Anyone who can use the FreePBX admin GUI can therefore firewall any
  address but loopback — a Banned or Deny blocks every port, the GUI and SSH included — or put
  any address on those PBX jails' ignore lists, never SSH's. Keep your own address in `ignoreip`
  in `/etc/fail2ban/jail.local`.
- **A ban alone is not a firewall.** It is answered by the provisioning endpoint
  after FreePBX has booted for the request, with a 403 — it does not touch SIP,
  the admin GUI or any other port, and costs the caller no more than a refused
  request does. A banned MAC or user is only as good as the MAC or username
  the caller chooses to send; an address ban is the one a caller cannot pick.
  The 403's body is a bare `Forbidden`; which ban refused it, and what that ban
  names, is on the Logs tab only.
