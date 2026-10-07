# Clients, profiles and resources

```
Client                    Profile              Resources
------                    -------              ---------
0004f282e824          ->  Polycom VVX 500  ->  .cfg
device 1001 (ext 1001)                         phone.cfg
                                               {{device.mac}}-directory.xml

00908f3bbcba          ->  Polycom VVX 500
device 1002 (ext 1002)
```

**Client** — a MAC address, a FreePBX device and a profile, each optional. A
MAC is unique, and stored as 12 lowercase hexadecimal characters with any
separators stripped. A client without one can be written ahead of the handset
arriving, and is served nothing until its MAC is filled in. The FreePBX `devices`
table stays the source of truth for the device, its extension and its
description, so a client carries none of its own.

A client can also be told **where the phone is**: a **private IP**, the address
the handset answers its own web interface on, and a **public IP**, the address
the site it sits behind is reached at from outside. Both are written down rather
than discovered — nothing in the module connects to a phone, so nothing can fill
them in — and both must be an IPv4 or IPv6 address. Given a private address, the
client's row on the Clients list grows a button that opens the handset's web
interface in a new tab; the public one is kept for reference. Both are searched
by the box above the list, and both render into a configuration as
`{{client.private_ip}}` and `{{client.public_ip}}`.

A client can also be given a **token**: a secret typed as `user:password` —
`username:password` — and hashed with `password_hash()` when you save. The
Token box holds that hash from then on, so leaving it alone leaves the token
alone, typing a new `user:password` over it replaces it, and emptying it takes
it away. A new client saved with the box empty is given a generated one — a
random `user:password`, shown once in a prompt when you save, and never again.
**A colon is what marks a value as a token still to be hashed**; a
value without one is stored as it was typed and verifies against nothing.

While a client has a token, the endpoint refuses it everything until it
presents one: the request needs HTTP Basic credentials, and `user:password` is
checked with `password_verify()` against the stored hash. A wrong token, or
none, is a `401` with a `WWW-Authenticate` header — asked for as soon as a
filename has matched, and before the resource's type is looked at, so a 401
cannot tell an unauthenticated caller which files exist.

**Profile** — a name, and the files it serves. Nothing else: a profile holds no
configuration text of its own.

**Resource** — one file. A filename, and a type that says what the filename
gets: **Template** (rendered for the client that asked), **File** (uploaded
here, served byte for byte) or **Log** (received from the phone rather than
served to it, and read back at the same URL). The main configuration file is a
resource like every other file, named `.cfg`.

**Service** — a name, and its links to other services. A service can be under
any number of **parents** and have any number of services under it, so one
service is shared rather than copied: make `Voicemail` once and tick it under
both `Basic User` and `Advanced User`. Both sides are edited on the service's
page, as a list of every other service to tick. A service cannot be under
itself or under anything beneath it — those choices are greyed out. Deleting a
service removes its links and leaves the services over and under it in place.
A service with at least one service under it is a **service pack**, ticked in
the Service Pack column of the Services list; nothing is set to make it one.
Every service also has a **slug** — `call-recording` — unique, lowercase
letters and digits joined by hyphens. It is made from the name and is not editable:
renaming a service gives it a new slug, and everything that referred to the
old one is updated with it. It is the last part of the service's page address.

Some services come **with the module**, marked `module` on the list: Advanced
User, Basic User, Guest User, Call Recording, Find Me Follow, On Demand
Recording, Support and Voicemail, grouped into packs. They cannot be edited
or deleted, but a service of your own can be put under any of them, or over
it, from your service's page. They are written when the module is installed or
upgraded.

Nothing a phone is served depends on a service.

A client with no device still provisions — the device-derived placeholders are
simply empty, which is what a profile of static configuration wants. A client
with no profile is served the profile named after its vendor, when there is
one — see [A client with no profile](endpoint.md#a-client-with-no-profile) —
and otherwise nothing.
