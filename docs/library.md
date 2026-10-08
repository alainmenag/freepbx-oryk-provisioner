# The library

The module ships ready-made profiles. Starting from one gives you its resources
already written, instead of a profile set up one file at a time.

## Starting a profile from the library

1. **Profiles**, then **Add Profile**.
2. Choose an entry under **Start From**. The name is filled in for you; change
   it if you like.
3. Save. The profile opens with the entry's resources on its **Resources** tab.

The resources are copies, and yours from then on. Edit, rename or delete them
like any other; nothing a later version of the module ships changes them. The
profile's page says which entry it was made from, and that entry's version at
the time.

**Empty** is a profile with no resources, as before.

## What a library profile contains

| Type | What you get |
| --- | --- |
| Template | the configuration text, written with [placeholders](templates.md) so it needs no editing to work on your PBX |
| Log | a resource ready to receive that file from the phone |
| File | either the file itself, or a resource with nothing uploaded and a line saying what to upload |

Firmware is never shipped. A profile that needs it has a File resource with
nothing on it yet: open the resource, read what it asks for, and upload it.
Until you do, a phone asking for that file is answered that it is missing.

## Entries

| Entry | Known to work on | Resources |
| --- | --- | --- |
| **AudioCodes 420HD** | 420HD | `.cfg` |
| **Grandstream GRP** | GRP2613 | `cfg{{device.mac}}.xml` |
| **Polycom VVX** | VVX500 | `{{device.mac}}.cfg`, `phone.cfg`, `web.cfg`, `app.log`, `boot.log`, and `sip.ld` for the firmware you upload |
| **WebKit** | browsers (WebKit) | `{{device.mac}}.cfg`, as plain `KEY=value` lines |
| **Cisco SPA / MPP (untested)** | untested | `spa{{device.mac}}.xml` |
| **Fanvil X-Series (untested)** | untested | `{{device.mac}}.cfg` |
| **Mitel 6800 (untested)** | untested | `.cfg` |
| **Yealink T-Series (untested)** | untested | `{{device.mac}}.cfg` |

An entry marked **(untested)** was written from the vendor's documented format
and has not been run on a phone. It registers one line and nothing else; treat
it as a starting point, and rename the profile once it works for you.

`web.cfg` points the phone at the **Provisioning Server** setting — see
[Settings](admin.md#settings). It does not set the phone's provisioning user or
password: those stay as they were entered on the phone.
