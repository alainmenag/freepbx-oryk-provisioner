# Oryk Provisioner

Oryk Provisioner is a template-driven VoIP endpoint provisioning module for FreePBX.

The goal is to provide a vendor-neutral provisioning layer that separates **device data** from **device-specific configuration formats**.

Provisioning templates define how configuration files should look, while profiles provide the values inserted into those templates.

The initial implementation focuses on softphone provisioning, with support planned for physical devices including Yealink, Poly, Grandstream, and other SIP endpoints.

## Goals

* Provide a standardized provisioning model inside FreePBX.
* Keep device configuration data separate from vendor-specific configuration syntax.
* Support reusable device templates.
* Support one or more generated files per device.
* Generate JSON, XML, key/value, CFG, INI, and arbitrary text-based configuration files.
* Allow device-specific parameters to override template defaults.
* Automatically consume FreePBX extension and SIP configuration where appropriate.
* Provide provisioning URLs that endpoints can consume directly.
* Support vendor-specific provisioning behavior without changing the core provisioning engine.
* Provide both an administrative interface and API for managing devices and templates.

---

# Concept

The provisioning model is inspired in part by BroadWorks device management.

A **template** describes how a device or application should be provisioned.

A **device** is an instance of that template and contains the parameters necessary to render the final configuration.

Conceptually:

```text
FreePBX Extension Data
        +
Template Defaults
        +
Device Parameters
        ↓
   Parameter Context
        ↓
   Template Engine
        ↓
Rendered Output Files
        ↓
JSON / XML / CFG / INI / TXT / Vendor Format
```

The provisioning engine works with normalized parameters such as:

```text
sip.username
sip.password
sip.domain
sip.port
sip.transport

device.id
device.mac
device.model
device.vendor

user.extension
user.display_name
user.email

server.address
server.port
```

Each template decides how those parameters are represented in the final device configuration.

---

# Templates

A template defines the provisioning behavior for a device family, softphone, or other SIP endpoint.

A template may generate one or more output files.

For example, a simple softphone template may generate only:

```text
config.json
```

A physical phone may require:

```text
001565AABBCC.cfg
directory.xml
favorites.xml
```

All generated files share the same resolved parameter context.

## Template Example

```json
{
    "name": "Generic Softphone",
    "slug": "generic-softphone",
    "vendor": "generic",
    "family": "softphone",
    "defaults": {
        "sip.port": 5060,
        "sip.transport": "udp"
    },
    "outputs": [
        {
            "filename": "config.json",
            "contentType": "application/json",
            "template": "{ ... }"
        }
    ]
}
```

A Yealink-style template could define:

```json
{
    "name": "Yealink T54W",
    "slug": "yealink-t54w",
    "vendor": "yealink",
    "family": "T5",
    "defaults": {
        "sip.port": 5060,
        "sip.transport": "udp"
    },
    "outputs": [
        {
            "filename": "{{device.mac}}.cfg",
            "contentType": "text/plain",
            "template": "..."
        },
        {
            "filename": "directory.xml",
            "contentType": "application/xml",
            "template": "..."
        }
    ]
}
```

Templates are reusable across multiple devices.

---

# Template Outputs

Each template contains one or more outputs.

An output defines:

```json
{
    "filename": "{{device.mac}}.cfg",
    "contentType": "text/plain",
    "template": "..."
}
```

The filename itself may contain template variables.

Examples:

```text
config.json

{{device.mac}}.cfg

cfg{{device.mac}}.xml

{{device.mac}}-directory.xml
```

This allows the provisioning engine to support vendor-specific filename requirements without hardcoding them into the core provisioning system.

---

# Template Variables

Templates use normalized variables.

Example JSON template:

```json
{
    "account": {
        "username": "{{sip.username}}",
        "password": "{{sip.password}}",
        "domain": "{{sip.domain}}",
        "port": "{{sip.port}}",
        "transport": "{{sip.transport}}"
    },
    "user": {
        "extension": "{{user.extension}}",
        "displayName": "{{user.display_name}}"
    }
}
```

The same data could be rendered as key/value configuration:

```ini
account.username={{sip.username}}
account.password={{sip.password}}
account.domain={{sip.domain}}
account.port={{sip.port}}
account.transport={{sip.transport}}
```

or XML:

```xml
<account>
    <username>{{sip.username}}</username>
    <password>{{sip.password}}</password>
    <domain>{{sip.domain}}</domain>
    <port>{{sip.port}}</port>
    <transport>{{sip.transport}}</transport>
</account>
```

The provisioning engine resolves the values.

The template determines the final syntax.

---

# Parameter Schema

Templates may declare the parameters they expect.

This makes parameters discoverable by the administrative interface and allows validation before a configuration is rendered.

Example:

```json
{
    "parameters": {
        "sip.username": {
            "type": "string",
            "required": true
        },
        "sip.password": {
            "type": "string",
            "required": true,
            "secret": true
        },
        "sip.port": {
            "type": "integer",
            "default": 5060
        },
        "sip.transport": {
            "type": "string",
            "default": "udp",
            "allowed": [
                "udp",
                "tcp",
                "tls"
            ]
        },
        "device.mac": {
            "type": "string",
            "required": false
        }
    }
}
```

Possible parameter properties include:

```text
type
required
default
secret
allowed
description
```

The administrative interface can use this schema to automatically build device parameter forms.

---

# Devices

A device represents a provisioned endpoint.

Example:

```json
{
    "name": "Alain Softphone",
    "template": "generic-softphone",
    "identifier": "alain-softphone",
    "extension": "1001",
    "enabled": true,
    "parameters": {
        "sip.transport": "tls"
    }
}
```

A physical device may additionally contain:

```json
{
    "mac": "001565AABBCC",
    "vendor": "yealink",
    "model": "T54W"
}
```

Values such as SIP username, password, display name, server address, and other FreePBX information may be resolved automatically from the extension associated with the device.

Device parameters may override those values when necessary.

---

# Device State

Devices may be enabled or disabled.

```json
{
    "enabled": true
}
```

An enabled device may retrieve its provisioning configuration.

A disabled device must not return configuration files even if its provisioning token is valid.

This allows administrators to immediately stop provisioning without deleting the device.

---

# Parameter Resolution

Configuration values are resolved in the following order:

```text
Template Defaults
      ↓
FreePBX / Extension Values
      ↓
Device Parameters
      ↓
Resolved Parameter Context
```

Device-level parameters have the highest priority.

For example:

```text
Template Default:
sip.transport = udp

FreePBX:
sip.transport = udp

Device Override:
sip.transport = tls
```

The rendered value becomes:

```text
sip.transport = tls
```

---

# Provisioning API

Oryk Provisioner separates the **management API** from the **device provisioning endpoint**.

---

# Management API

Management operations integrate with the FreePBX API.

FreePBX GraphQL requests use:

```text
POST /admin/api/api/gql
```

The Provisioner module may expose queries such as:

```graphql
provisionerTemplates
provisionerTemplate(id: ID!)

provisionerDevices
provisionerDevice(id: ID!)

provisionerRenderDevice(id: ID!)
provisionerRenderFile(deviceId: ID!, filename: String!)
```

and mutations such as:

```graphql
provisionerCreateTemplate(...)
provisionerUpdateTemplate(...)
provisionerDeleteTemplate(...)

provisionerCreateDevice(...)
provisionerUpdateDevice(...)
provisionerDeleteDevice(...)

provisionerEnableDevice(id: ID!)
provisionerDisableDevice(id: ID!)

provisionerRegenerateToken(id: ID!)
```

The management API requires normal FreePBX API authentication and authorization.

---

# Provisioning Endpoints

Oryk Provisioner supports provisioning through the FreePBX web interface as well as optional simplified provisioning URLs.

## FreePBX Provisioning URL

The canonical FreePBX provisioning endpoint is:

```text
/admin/config.php?display=oryk_provisioner&token={token}&filename={filename}
```

Example:

```text
/admin/config.php?display=oryk_provisioner&token=9f31d772d2d742c792b5c93fb1c52a51&filename=config.json
```

For a physical phone:

```text
/admin/config.php?display=oryk_provisioner&token=9f31d772d2d742c792b5c93fb1c52a51&filename=001565AABBCC.cfg
```

When a valid provisioning token and filename are supplied, the module:

```text
Validate Token
      ↓
Resolve Device
      ↓
Check Device Enabled
      ↓
Resolve Template
      ↓
Resolve Parameters
      ↓
Match Requested Output
      ↓
Render Template
      ↓
Return Raw Configuration
```

The response contains the raw configuration rather than the FreePBX administrative interface.

Example:

```http
GET /admin/config.php?display=oryk_provisioner&token=9f31d772d2d742c792b5c93fb1c52a51&filename=config.json
```

Response:

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

```json
{
    "account": {
        "username": "1001",
        "password": "secret",
        "domain": "pbx.example.com",
        "transport": "tls"
    }
}
```

---

# Friendly Provisioning URL

Where URL rewriting is available, the same configuration may optionally be exposed through a shorter device-friendly URL:

```text
/provisioner/{token}/{filename}
```

Example:

```text
/provisioner/9f31d772d2d742c792b5c93fb1c52a51/001565AABBCC.cfg
```

The friendly URL resolves to the same provisioning engine as the FreePBX URL.

It does not represent a separate provisioning implementation.

Conceptually:

```text
/provisioner/{token}/{filename}
                │
                ▼
        Provisioning Engine

/admin/config.php?display=oryk_provisioner
        &token={token}
        &filename={filename}
                │
                ▼
        Provisioning Engine
```

---

# Administrative Interface

Requests without a provisioning token provide the normal FreePBX administrative interface:

```text
/admin/config.php?display=oryk_provisioner
```

The administrative interface is used to manage:

* Devices
* Templates
* Template outputs
* Device parameters
* Template defaults
* Parameter schemas
* Provisioning tokens
* Device enable/disable state
* Generated configuration previews

---

# Configuration Preview

Administrators should be able to render a configuration without making an actual provisioning request.

The administrative interface may provide actions such as:

```text
Preview Device
Preview Output
View Resolved Parameters
```

For example:

```text
Device: Alain Softphone
Template: Generic Softphone
Output: config.json
```

The preview should show both:

```text
Resolved Parameters
```

and:

```text
Rendered Configuration
```

This allows template problems to be identified before assigning them to production devices.

---

# Provisioning Tokens

Each device receives a random provisioning token.

Example:

```text
Device ID:       42
MAC:             001565AABBCC
Provision Token: 9f31d772d2d742c792b5c93fb1c52a51
```

A provisioning URL may therefore look like:

```text
https://pbx.example.com/admin/config.php?display=oryk_provisioner&token=9f31d772d2d742c792b5c93fb1c52a51&filename=001565AABBCC.cfg
```

or:

```text
https://pbx.example.com/provisioner/9f31d772d2d742c792b5c93fb1c52a51/001565AABBCC.cfg
```

MAC addresses identify devices but should not be treated as authentication credentials.

The provisioning token provides access to the device's generated configuration.

---

# Token Lifecycle

Provisioning tokens may be:

```text
Created
Regenerated
Revoked
```

Regenerating a token immediately invalidates the previous provisioning URL.

Deleting or disabling a device also prevents the token from being used.

Tokens should be generated using a cryptographically secure random value and should not contain predictable information such as:

```text
device ID
extension
MAC address
username
```

---

# Provisioning HTTP Behavior

The provisioning endpoint should return predictable HTTP responses.

## Successful Configuration

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

or the content type defined by the template output.

## Invalid Token

```http
HTTP/1.1 404 Not Found
```

The response should not reveal whether a device exists.

## Disabled Device

```http
HTTP/1.1 404 Not Found
```

## Unknown Filename

```http
HTTP/1.1 404 Not Found
```

## Template Rendering Failure

```http
HTTP/1.1 500 Internal Server Error
```

Detailed rendering errors should be logged internally but should not expose sensitive device parameters to the endpoint.

---

# Example Provisioning Flow

Given the device:

```json
{
    "extension": "1001",
    "template": "generic-softphone",
    "enabled": true,
    "parameters": {
        "sip.transport": "tls"
    }
}
```

FreePBX may provide:

```json
{
    "sip.username": "1001",
    "sip.password": "A5f9bB2...",
    "sip.domain": "pbx.example.com",
    "sip.port": 5061,
    "user.extension": "1001",
    "user.display_name": "Alain"
}
```

The template provides:

```json
{
    "sip.port": 5060,
    "sip.transport": "udp"
}
```

The device overrides:

```json
{
    "sip.transport": "tls"
}
```

The resulting context becomes:

```json
{
    "sip.username": "1001",
    "sip.password": "A5f9bB2...",
    "sip.domain": "pbx.example.com",
    "sip.port": 5061,
    "sip.transport": "tls",
    "user.extension": "1001",
    "user.display_name": "Alain"
}
```

That context is then available to every output defined by the assigned template.

---

# Multiple Output Example

A Yealink device could use:

```json
{
    "name": "Office T54W",
    "template": "yealink-t54w",
    "mac": "001565AABBCC",
    "extension": "1001"
}
```

The template may define:

```json
{
    "outputs": [
        {
            "filename": "{{device.mac}}.cfg",
            "contentType": "text/plain",
            "template": "..."
        },
        {
            "filename": "{{device.mac}}-directory.xml",
            "contentType": "application/xml",
            "template": "..."
        }
    ]
}
```

The same device can then request:

```text
/provisioner/{token}/001565AABBCC.cfg
```

and:

```text
/provisioner/{token}/001565AABBCC-directory.xml
```

Both files are generated from the same device and parameter context.

---

# Security

Provisioning files may contain sensitive information including SIP credentials.

The provisioning system should therefore:

* Use HTTPS whenever possible.
* Use random provisioning tokens.
* Never expose SIP credentials through management APIs without appropriate authorization.
* Avoid logging rendered configuration contents containing secrets.
* Allow tokens to be regenerated.
* Allow devices to be disabled immediately.
* Return generic responses for invalid provisioning requests.
* Support additional network restrictions where appropriate.

Vendor-specific authentication methods may be added where supported.

---

# Planned Device Support

The initial focus is a generic softphone provisioning format.

Planned device families include:

* Generic SIP softphones
* Yealink
* Poly / Polycom
* Grandstream

The provisioning engine itself remains vendor-neutral.

Vendor support should primarily consist of:

```text
Templates
Parameter mappings
Output definitions
Filename rules
Content types
Vendor-specific behavior
```

rather than separate provisioning engines for every manufacturer.

---

# Device Types

As the module grows, templates may represent complete device types or device profiles.

For example:

```text
Device Type
└── Yealink T54W
    ├── Parameters
    ├── Defaults
    └── Outputs
        ├── {{device.mac}}.cfg
        ├── directory.xml
        └── favorites.xml
```

or:

```text
Device Type
└── Poly Edge E450
    ├── Parameters
    ├── Defaults
    └── Outputs
        ├── {{device.mac}}.cfg
        ├── phone.cfg
        └── contacts.xml
```

A single physical device can therefore generate multiple provisioning files while sharing the same resolved parameter context.

This provides a path toward a BroadWorks-style device management model without coupling the provisioning engine to any individual manufacturer.

---

# Architecture

At a high level:

```text
                    ┌─────────────────────┐
                    │      FreePBX        │
                    │ Extensions / PJSIP  │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │ Parameter Resolver  │
                    └──────────┬──────────┘
                               │
             ┌─────────────────┼─────────────────┐
             │                 │                 │
             ▼                 ▼                 ▼
      Template Defaults   FreePBX Data    Device Overrides
             │                 │                 │
             └─────────────────┼─────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │ Resolved Context    │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │  Template Renderer  │
                    └──────────┬──────────┘
                               │
                     ┌─────────┴─────────┐
                     ▼                   ▼
              config.json        001565AABBCC.cfg
```

The same renderer should be used by:

```text
FreePBX Admin Preview
GraphQL API
FreePBX Provisioning URL
Friendly Provisioning URL
```

This keeps provisioning behavior consistent regardless of how the configuration is requested.
