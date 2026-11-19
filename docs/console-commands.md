---
title: Console commands
slug: console-commands
order: 90
summary: List rules, send tests, catch scheduled posts and prune the delivery log.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

```bash
php artisan craft:switchboard:rules
```
Rules with their trigger, user channels, how many destinations, and sent/failed counts for the last 24 hours.
Destinations themselves are never printed.

```bash
php artisan craft:switchboard:test <rule-handle> --user=<username or email> [--element=<id>] [--destinations]
```
Sends the rule's message to one user through its channels, about that element or the newest one from the rule's
sources the user can view. `--destinations` also posts to the rule's ad-hoc destinations.

```bash
php artisan craft:switchboard:scan [--since="-1 hour"]
```
Fires “An entry is published” for entries whose post date passed since the last run — Craft saves nothing when that
happens. Scheduled every five minutes; publications that already notified are never notified again.

```bash
php artisan craft:switchboard:prune [--days=30]
```
Deletes delivery records older than the retention setting. Scheduled daily.
