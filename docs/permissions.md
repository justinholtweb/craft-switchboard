---
title: Permissions and privacy
slug: permissions
order: 50
summary: Nobody is told about content they can't view, and nobody sees fields they couldn't see in Craft.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## Who gets told

Before anything is rendered for a Craft user, Switchboard checks:

1. They're **active** — not pending, suspended, locked or deactivated.
2. They **can view the element**, with Craft's own permission check (sections, peer entries, volumes, users).
   The user an event is about may always be told about themselves.
3. On a multi-site install, they **can access the element's site**.
4. Failed-job notifications go to **admins** only.

Anyone who fails a check is skipped, and the skip (with its reason) is in the delivery log.

## What they're told

Messages are built from a per-recipient snapshot of the element, never from the element itself:

- a custom field is included only if it's listed under **Fields to include** *and* the recipient could see it in the
  element editor — a user condition on the field or its tab hides it, exactly as in Craft;
- related elements appear only if the recipient can view them;
- email addresses and usernames of users appear only for people allowed to view that user;
- control panel links only go to people with control panel access.

**Ad-hoc destinations** (an email address that isn't a Craft user, Slack, Discord, Teams, webhooks, SMS) are
anonymous viewers: no conditional fields, no email addresses or usernames, no CP links, live related elements only,
and no error messages from failed jobs. Treat what you route to them as public.

## Who can set rules up

| | Admins | “Manage notification rules” |
|---|---|---|
| Rules on any source | ✓ | only sources they can view, named explicitly |
| Failed-job and custom triggers | ✓ | — |
| Ad-hoc destinations | ✓ | — |
| Body templates (`_switchboard/`) | ✓ | — |
| Change or delete a rule with destinations | ✓ | — |
| Delivery log | everything | only deliveries about elements they can view |

Conditions only read attributes and fields anyone could see, and **Send test** ignores conditions and answers the
same either way, so neither can be used to learn hidden content.
