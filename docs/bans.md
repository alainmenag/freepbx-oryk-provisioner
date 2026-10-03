# Bans

The **Bans** tab says what the provisioning endpoint does with requests that
match a rule. Bans are checked before the endpoint answers anything, and a
refusal is a **403**, written to the Logs tab like any other request. Bans touch
only the provisioning endpoint — not SIP, not the admin GUI.

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
profile, so a Profile ban does not stop it. One address at a time; no ranges. Two bans naming exactly the same fields and
values are not allowed — the second is refused and points you at the first.

## What it does

| State | Does | Until |
| --- | --- | --- |
| **Banned** | refuses | the minutes you give it run out; the row is then deleted |
| **Deny** | refuses | the row is deleted |
| **Allow** | serves in spite of a less specific ban | the row is deleted |

Saving a Banned row again starts its time over from that save.

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

## Keeping it tidy

Deleting a client or a profile deletes every ban naming it. A ban naming a user keeps the
number when the user is deleted or renumbered; a ban naming a MAC stays when its
client is deleted. Expired Banned rows disappear from the list on their own.

The editor warns before banning an address on its own when it is the address
you are connected from, or one written on a client as its Public IP — both stop
phones you probably care about. Neither is refused: a ban never locks you out
of the GUI.
