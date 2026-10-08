# Template placeholders

`{{name}}`, filled in when a client asks for the file. A name nothing answers to
renders as nothing — a phone copes with an empty value, not with a literal
`{{ }}` where a value belongs. Both editors list the names under the template
box; click one to copy it.

| Placeholder | Value |
| --- | --- |
| `{{device.mac}}` | `0004f282e824` |
| `{{device.mac_upper}}` | `0004F282E824` |
| `{{device.mac_colon}}` | `00:04:f2:82:e8:24` |
| `{{device.mac_colon_upper}}` | `00:04:F2:82:E8:24` |
| `{{device.id}}` | the FreePBX device id |
| `{{device.description}}` | its description |
| `{{device.tech}}` | `pjsip` or `sip` |
| `{{device.username}}` | SIP username |
| `{{device.secret}}` | SIP secret |
| `{{client.id}}` | the client's id — the number in its page address, and the directory its uploaded logs are stored in |
| `{{client.private_ip}}` | where the handset is on the local network |
| `{{client.public_ip}}` | where the site it sits behind is reached |
| `{{extension.number}}` | the extension the device is attached to |
| `{{extension.name}}` | display name |
| `{{extension.voicemail}}` | voicemail setting |
| `{{extension.services}}` | the user's services, as slugs joined by commas: `guest-user,knowledge-base`. One that reaches the user through a service pack is listed like one assigned; in alphabetical order; empty when the user has none |
| `{{profile.id}}`, `{{profile.name}}` | the profile serving the file |
| `{{server.host}}` | the Hostname setting (`ORYK_HOSTNAME`); blank, the host the request arrived on, port stripped |
| `{{server.port}}` | `5060` |

Plus **everything else the device is configured with in FreePBX**, under a `sip.`
prefix — one placeholder per row the device has in the `sip` table, so
vendor-specific values stay in your templates rather than in the module:

```
{{sip.transport}}   {{sip.callerid}}   {{sip.dtmfmode}}   ...
```

Non-alphanumerics in a keyword fold to `_`, so `dtmf-mode` is `{{sip.dtmf_mode}}`.

There are no filters, sections or escaping.

A site whose `sip` or `users` tables are missing a lookup degrades to empty
values rather than a 500.
