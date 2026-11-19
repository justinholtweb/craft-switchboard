---
title: Installation
slug: installation
order: 10
summary: Requirements, the Craft 6 beta caution, install, the queue, and your first rule.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## Requirements

- Craft CMS 6.0.0-beta.1 or later, PHP 8.5
- A queue worker. Every delivery is a queued Laravel notification: run `php artisan queue:work` (add
  `--queue=<name>` if you set Switchboard's `queue` setting) under Supervisor or your host's equivalent.
- Laravel's scheduler (`* * * * * php artisan schedule:run`), for scheduled posts and log pruning.
- Mail configured in Craft (Settings → Email) for email notifications.

## Install

```bash
composer require justinholtweb/craft-switchboard
php artisan craft:plugin:install switchboard
```

Switchboard is free, under the Craft License.

## Your first rule

1. **Switchboard → Rules → New rule.** Name it, pick **An entry is published**, tick the sections it watches.
2. Under **Who**, tick a user group and the **Notification bell** and **Email** channels.
3. Leave the message blank for a translated default, or write your own (see [Messages](messages)).
4. Save, then **Send test to me** at the bottom of the rule.

Publish an entry: the group's members who can view it get a bell notification and an email, in their own
language. **Switchboard → Deliveries** shows each delivery, and why anyone was skipped.
