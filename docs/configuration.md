---
title: Configuration
slug: configuration
order: 100
summary: Settings and config/craft/switchboard.php.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Settings → Plugins → Switchboard, or `config/craft/switchboard.php` (which wins):

| Setting | Default | |
|---|---|---|
| `queue` | Craft's queue | the queue Switchboard's jobs go on |
| `webhookTimeout` | `5` | seconds, 1–30 |
| `maxRecipients` | `500` | the most people one rule notifies about one event |
| `logRetentionDays` | `30` | delivery records older than this are pruned |
| `allowedHosts` | `[]` | `host` or `host:port` entries webhooks may reach although private or plain HTTP — development only (config file only) |
| `showInCpNav` | `true` | the sidebar link |
| `cpNavLabel`, `cpNavIcon` | — | the sidebar link's label and icon (a Craft icon or one from a custom icon set) |

The sidebar shows a badge with the number of deliveries that failed in the last 24 hours, to people who can view
the delivery log.
