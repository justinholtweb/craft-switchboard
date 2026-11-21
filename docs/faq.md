---
title: FAQ
slug: faq
order: 110
summary: Common questions.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

**Nothing arrives.** Is a queue worker running on Switchboard's queue? Check **Switchboard → Deliveries**: skipped
rows say why (can't view, no site access, not active).

**Why wasn't a user notified about an entry?** They can't view it — Craft's “View entries” (and “View other users'
entries”) permissions for that section — or, on multi-site, can't access its site.

**Will an entry that's unpublished and published again notify twice?** Not with the same post date: “published” means
the first time an entry goes live with a given post date. Change the post date to announce it again.

**Can editors set up Slack notifications?** Admins add destinations. Editors with “Manage notification rules” build
rules that notify Craft users.

**Where are rules stored?** In the database (`switchboard_rules`), not project config: they hold destinations and
signing secrets, which shouldn't be in git. Destinations and secrets are encrypted with your app key.

**Can Switchboard send scheduled digests?** Not yet.

## How is it different from Control Tower?

[Control Tower](https://craft-controltower.com) is an operations dashboard for Craft 5. It watches
traffic, editors, the queue and the server, and its alert rules can post to Slack, Teams or email
when a threshold is crossed.

Switchboard is about **content and people**, on Craft 6: an entry published, updated or deleted, a
user registered or activated, an asset uploaded, or your own event, sent to Slack, Discord, Teams,
SMS, email or the control-panel bell. They overlap on one trigger: both can tell you a queued job
failed.

## Isn't this what Schedulr or Lyfe do?

No. Those plugins message your **audience**, on Craft 5.
Schedulr sends scheduled web push, email and on-site
notifications to subscribers. Lyfe runs marketing automations for Commerce customers. Switchboard
tells **your team** that something happened on the site.
