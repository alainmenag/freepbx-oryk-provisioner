# Security

Know what this is before you expose it:

- **A client with no token is keyed on MAC address alone.** Anyone who can
  reach the URL and knows — or guesses — a MAC gets that client's rendered
  configuration, including `device.secret` if the template emits it. Giving the
  client a token closes that: the endpoint then answers it nothing without HTTP
  Basic credentials that verify against the stored hash. Nothing makes you set
  one, so a fleet provisioned without tokens is as open as it ever was —
  restrict who can reach `/provisioner/` at the network layer, and prefer
  HTTPS, since Basic credentials over plain http are credentials in the clear.
- **A token is not rate limited and there is no lockout.** A wrong one costs
  the caller a single bcrypt verification over an endpoint anyone can reach.
- **An uploaded File resource is served to a caller with no client behind it
  at all**, matched by name across every enabled profile — which is what lets a
  phone fetch firmware before anybody has written its client, and also means
  anyone who reaches the endpoint and knows the name can fetch it.
- **Failures are not uniform.** A 404 says which kind of failure it was
  ("… is not associated with anything", "… has no profile assigned",
  "… is disabled", "The … profile is disabled"), so a caller probing MACs can
  tell a known one from an unknown one.
- **Secrets are not masked anywhere in the UI.** The Render links serve the real
  rendered file, secret included.
- **Open provisioning creates PBX users from an unauthenticated request.**
  With **Provisioning** Open and **Open Provisioning Networks** blank, anyone
  who reaches the endpoint can create an extension, account and mailbox per
  username they invent, each costing a full reload. Set the networks. A wrong
  password for an existing username is written to FreePBX's security log, which
  is what FreePBX's own GUI jail watches; new usernames are not failures and
  are not banned.
- **Deleting a user deletes its call history and recordings** — see
  [Deleting](users.md#deleting).
- The provisioning log records metadata only — MAC, file, profile — never the
  rendered body or any parameter value.
- Sortable columns are whitelisted and mapped to SQL names before being written
  into a statement; everything else is bound.
- **The Bans tab is root access, narrowed.** The sudo rule lets the web user run
  one root-owned file, which checks every argument itself and can do nothing
  but list, ban and unban. Anyone who can use the FreePBX admin GUI can ban or
  unban any address — including on `sshd`. Put your own address in `ignoreip`
  in `/etc/fail2ban/jail.local`.
