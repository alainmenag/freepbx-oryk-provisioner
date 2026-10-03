# Bans

The **Bans** tab says what the provisioning endpoint does with requests that
match a rule. Bans are checked before the endpoint answers anything, and a
refusal is a **403**, written to the Logs tab like any other request. On their
own, bans touch only the provisioning endpoint. A ban naming an IP address and
nothing else is also kept in step with fail2ban, when that is set up — see
[Syncing with fail2ban](fail2ban.md).

## What a ban matches

A ban names one or more of:

| Field | Matches |
| --- | --- |
| **IP Address** | requests from that address — every phone behind it |
| **MAC Address** | requests made with that MAC, whether or not a client has it |
| **User** | every client whose device or extension is that number, and open provisioning with that number as its username |
| **Client** | that one client, whatever MAC it is given later |
| **Profile** | every client served that profile — assigned it, or given it for its vendor because it has none |

A request matches a ban when it matches **every** field the ban fills in. A
field left empty matches anything. So:

- **IP Address** 203.0.113.7 alone refuses every phone at that site;
- **User** 1001 and **IP Address** 203.0.113.7 together match user 1001 only
  when it comes from there.

A file fetched by name with no client behind it (firmware, usually) has no
profile, so a Profile ban does not stop it. One address at a time; no ranges.

There is only ever one ban for the same fields and values. **Add Ban** with
exactly what an existing ban names reopens that ban — its state, minutes and
note (when you give one) replace the old ones, even if it had expired — and
takes you to it. Editing a ban so it names exactly what another one does is
refused; open the other one instead.

## What it does

| State | Does | Until |
| --- | --- | --- |
| **Banned** | refuses | the minutes you give it run out |
| **Deny** | refuses | the ban is deleted |
| **Allow** | serves in spite of a less specific ban | the ban is deleted |

An expired ban is not deleted: it stays on the list, greyed and marked
**expired**, and refuses nothing until it is deleted or saved again. Saving a
Banned ban again starts its time over from that save, which is also how an
expired one is put back in force — from its own page, or by adding the same
ban again.

## When bans disagree

The most specific ban decides. A ban naming a **Client** beats one naming a
**User**, which beats a **MAC Address**, then a **Profile**, then an **IP
Address**. Between
two that name the same most specific field, the one naming more fields wins,
and on a tie the refusal wins.

- A site's address is banned, but one client there is allowed: that client is
  served; every other phone at the site is refused.
- User 1001 is denied, but allowed from the office address: it is served from
  the office and refused anywhere else.
- An office address is allowed, but a MAC behind it is denied: the MAC is
  refused.
- A profile is denied, but allowed from the office address: its phones are
  served from the office and refused anywhere else.

Allow overrides bans and nothing else. A disabled client, a token or a disabled
profile still decide afterwards, as they always do.

## Open provisioning

The caller's address, and its username when it is a number, are checked before
any user or client is looked up or made — a refused caller creates nothing. The
client it is answered as is then checked like any other.

## Source and jail

A ban's page has two fields for what created it: **Source** — `manual` unless
you say otherwise, or the name of whatever adds bans on its own, such as
`fail2ban` — and **Jail**, the fail2ban jail or rule that fired, when there is
one. Change them on the ban's page like any other field. Adding the same ban
again keeps the source and jail it already has.

## Hits

**Hits** counts the requests each ban decided — refused, or let through for an
Allow — with when the last one was on hover, and on the ban's own page. Only
the ban that decided a request is counted, not every ban that matched it.
Editing a ban, or reopening it by adding the same one again, keeps its count;
deleting it is the only reset.

**Times** counts the separate periods a ban has been in force — a save or a
fail2ban ban that puts it back in force after it lapsed starts a new one —
next to Hits in the table and on the ban's page, where **Started** says when
the current period began.

## Keeping it tidy

Deleting a client or a profile deletes every ban naming it. A ban naming a user keeps the
number when the user is deleted or renumbered; a ban naming a MAC stays when its
client is deleted. Nothing else removes a ban: expired ones stay until you
delete them.

A loopback address (127.x.x.x, ::1) or the unspecified address can't be banned
at all. The editor warns before banning an address on its own when it is the
address you are connected from, or one written on a client as its Public IP —
both stop phones you probably care about, and with fail2ban syncing, a Deny on
your own address blocks you from the PBX entirely.
