# Jobs

A **job** is what the provisioner does when a user's services change. Saving
a user's Services tab, deleting a service, or putting a service
into a pack or taking it out all change what some users have; each of those
users gets one job, and it runs straight away.

## What makes one

| you | who gets a job |
| --- | --- |
| save a change on a user's Services tab — one service or several, as one job | that user |
| delete a service | everyone who had it — assigned it, or through a pack it was in |
| change a service's **Parents** or **Services** | everyone who has the pack it changes |
| upgrade the module, when a release regroups its own services | everyone that changes |

A job has one **step** for every service the user gains or loses: assigning a
pack gives a step for the pack and one for each service under it. A service
the user still has another way is not taken away — unassign Basic User from
someone who also has Advanced User and they keep Voicemail — and one they
already have is not given again. Saving a box that is already as it was, or
renaming a service, makes no job. Renumbering a user moves its jobs to the new
number and makes none.

Saving or deleting a service that changes users' services says so first: how
many users, and which services each gains or loses.

## What a step does

The provisioner's own services do something on the PBX:

| service | given | taken away |
| --- | --- | --- |
| Voicemail | a mailbox, in `default`, with a random PIN, where there is none | the mailbox is removed and the extension set to no voicemail — **its messages are kept**, and come back if it is given again |
| Call Recording | inbound and outbound, internal and external calls: *Force* | back to *Don't Care* |
| On Demand Recording | *Enable* | *Disable* |
| Find Me Follow | Find Me/Follow Me switched on, set up with its defaults where it never was | switched off; its list and settings are kept |

Voicemail and a new Find Me/Follow Me need the configuration applied. When
the last job has run — none queued or running for anyone — the provisioner
runs `fwconsole reload` once, if FreePBX says Apply Config is needed. That
applies **everything pending**, including changes of yours not yet applied,
as pressing Apply Config would. A reload that fails is logged, and Apply
Config stays up. Service packs, Support, Guest User, Lobby User and your own
services do nothing here — a guest's context is changed in Extensions — but
every step is also passed to any other module that hooks the provisioner
([hooks](hooks.md)): for every service, or only the ones it asks for.

## Running and failing

A user's jobs run one at a time, in the order they were made; different
users' run side by side. If a step fails — a module it needs is missing, or a
hooked module throws — **that job stops there, for that user only**. The user's
next job still runs, and the failed one is tried again after it. When there is
nothing left to run it waits: press **Retry** on it. Deleting a user deletes
its jobs. A job that fails because something changed since — a service given
and then taken away again — skips what is no longer true rather than undoing
the later change.

A module upgrade's jobs are run by a minute job rather than at once, so they
start within a minute of the upgrade.

## Where to see them

- **Jobs**, beside Logs and Bans: every job, newest first, filtered by state
  (Queued, Running, Done, Failed), reason and where it came from. The filters
  are in the address.
- A job's own page: each step, what it did, which modules finished it — the provisioner's own with the job it ran, as in `oryk_provisioner (Voicemail)` —, and the
  error from the one that failed, with **Retry** and **Delete**. Deleting a
  running job stops it before its next step; what it already did stays done.
- A user's **Services** tab: each service whose last job is queued, running or
  failed says so, with a link to the job.
- A user's **Overview**: its jobs.
- The **Jobs** dropdown under the section bar.

Finished jobs are deleted after 30 days. Failed ones are kept until they
succeed or you delete them.
