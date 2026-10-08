# Hooking the provisioner

Another FreePBX module can react when a user gains or loses a service. Every
[job step](jobs.md) is passed to every module that hooks it: the provisioner's
own reaction runs first, then each hooked module in turn.

## Declaring the hook

In your module's `module.xml`, the way you would hook Core:

```xml
<hooks>
	<oryk_provisioner class="Oryk_provisioner" namespace="FreePBX\modules" priority="500">
		<method callingMethod="serviceGranted" class="Mymodule" namespace="FreePBX\modules">onServiceGranted</method>
		<method callingMethod="serviceRevoked" class="Mymodule" namespace="FreePBX\modules">onServiceRevoked</method>
	</oryk_provisioner>
</hooks>
```

- `class="Oryk_provisioner"` and `namespace="FreePBX\modules"` must be written
  exactly so: FreePBX files the hook under `FreePBX\modules\Oryk_provisioner`,
  and that is the key the provisioner reads.
- Your module needs its own BMO class (`Mymodule.class.php`): FreePBX reads
  `module.xml` hooks only from modules that have one. With `class` the same as
  your module's BMO class, the method is called on it; another class is
  constructed with the FreePBX object.
- One method per event per module, as FreePBX allows: a second `<method>`
  with the same `callingMethod` is not called.
- FreePBX reads hooks when a module is installed or enabled, so install or
  upgrade yours (or the provisioner) after adding them. Disabled modules are
  not called.
- `priority` orders the hooked modules, lowest first.

## What you are given

One array:

| key | |
| --- | --- |
| `event` | `granted` or `revoked` |
| `extension` | the user |
| `service` | the service's slug — `voicemail`, `call-recording`, … |
| `name` | its name when the job was made |
| `via` | the assigned service the user gains or loses it through (`basic-user`), or `null` when it is that service itself |
| `reason` | `assigned`, `unassigned`, `service-deleted` or `pack-changed` |
| `source` | `gui`, or `upgrade` for a module upgrade's regrouping |
| `changed` | the slug of the service the change was made to: the one ticked, deleted or edited; `''` for an upgrade |
| `job`, `step` | ids, for the job's page (`?display=oryk_provisioner&job=<job>`) |
| `attempt` | 1 the first time this step is run, more on a retry |

```php
public function onServiceGranted(array $event)
{
	if ($event['service'] !== 'call-recording') {
		return;
	}

	// ... set it up for $event['extension']
}
```

## What you owe

- **Throw to fail.** An exception (or a PHP error) fails the step with your
  module's name and its message, and **stops that job for that user**: no
  later step of it runs, and no module after yours is called for this step.
  The job is tried again after the user's next job, or when an admin presses
  Retry; other users' jobs are not affected. Return normally for success —
  what you return is not read.
- **Be safe to call twice.** A retry starts at the module that threw, so a
  module before yours that finished is not called again, but yours may be: when
  it threw, or when the worker died while it ran.
- **Do not reload.** Raise Apply Config (`needreload()`) if you changed what it
  writes; the provisioner never reloads.
- **Do not count on the service still existing.** A `service-deleted` revoke
  arrives after the service has gone; `name` is all there is of it.
- **Expect to run in the background**, as the web user, from
  `bin/oryk-jobs` — not inside the request that made the change. Every module
  is loaded, as on a page.

A step is run only while it is still true: a grant only while the user has the
service, a revoke only while they do not. A grant that failed and was then
unassigned is skipped, not delivered late.

## Asking what a user has

```php
$provisioner = \FreePBX::Oryk_provisioner();

$provisioner->userServiceSlugs('1001');          // ['basic-user', 'find-me-follow', ...]
$provisioner->hasService('1001', 'voicemail');   // true
```

Both count what comes through a pack. `serviceGranted()` and
`serviceRevoked()` on the same object call every handler for one event
directly, outside any job — the first that throws stops the rest — and are
what the hook is declared against; changing a user's services is done on the
provisioner's pages, which make the job.
