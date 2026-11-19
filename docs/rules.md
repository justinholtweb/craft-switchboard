---
title: Rules
slug: rules
order: 20
summary: Triggers, sites, sources, conditions and recipients — and who may build rules.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

A rule says **when** (a trigger), **which** (sites, sources, conditions), **who** (recipients) and **how**
(channels, destinations).

## Triggers

| Trigger | Fires when |
|---|---|
| `entry.published` | an entry is live on a site after a save and wasn't before — once per entry, site and post date. Entries that go live because their post date passes are found by `craft:switchboard:scan` (every five minutes). |
| `entry.updated` | a live entry is saved again (not propagated saves, not bulk resaves, not drafts) |
| `entry.deleted` | an entry is deleted (trashed) |
| `user.registered` | a user account is created |
| `user.activated` | a user is activated |
| `asset.uploaded` | an asset is uploaded |
| `queue.failed` | a queued job fails for good. Switchboard's own jobs are ignored, so a failing notification can't trigger another. Admins only. |
| `custom:<name>` | your code calls `app(\JustinHoltWeb\Switchboard\Switchboard::class)->fire('<name>', $element, $data)` |

Drafts, revisions and provisional drafts never trigger anything. Rule processing is queued after the save's
database transaction commits, so a save that rolls back notifies nobody.

## Sites

On a multi-site install, tick the sites a rule covers. Entry triggers (and custom triggers fired with a
`siteHandle`) only fire for content on those sites; each site's entry is its own event, in its own language.

## Sources and conditions

Sources are the sections (entry triggers), volumes (asset triggers) or user groups (user triggers) a rule
watches; none ticked means all of them. Conditions are rows of *attribute — operator — value*, all of which must
match, case-insensitively:

- attributes: `title`, `slug`, `uri`, `status`, `section`, `type`, `volume`, `filename`, or a custom
  field's handle; for failed jobs `job`, `queue`, `connection`, `exception`; for custom triggers, any key of the data
- operators: `equals`, `notEquals`, `contains`, `notContains`, `empty`, `notEmpty`

Conditions only read what **anyone** could see: a field behind a user condition can't be tested (it reads as
empty), and neither can usernames or email addresses. A rule can't be used to learn a value its author can't see.

## Recipients

- **Users** (usernames, emails or IDs), **user groups**, **admins**, the **entry's authors**, and for user
  triggers **the user it's about** (e.g. a welcome message).
- **Ad-hoc destinations** (admins only): email addresses, Slack / Discord / Teams URLs, webhooks, phone numbers.
  An email address that belongs to a Craft user is treated as that user.

Each Craft user is checked before anything is rendered for them — see [Permissions and privacy](permissions).
A safety limit (`maxRecipients`, default 500) caps how many people one rule notifies about one event.

## Who can build rules

Two permissions: **Manage notification rules** and **View the delivery log**. People who aren't admins:

- build rules only on sources they can view themselves, and must name them; failed-job and custom triggers are admin-only
- can name other users as recipients only if they have Craft's “View users” permission
- can't add ad-hoc destinations or choose a body template, and can't change or delete a rule that has destinations
- see only deliveries about elements they can view in the delivery log
