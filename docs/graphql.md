---
title: GraphQL
slug: graphql
order: 80
summary: Read your own bell notifications with a user's access token. Shared tokens never see them.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Switchboard adds one GraphQL schema permission, **Switchboard → Read your own notifications**, and two read-only
queries:

```graphql
{
  switchboardUnreadCount
  switchboardNotifications(limit: 10, unreadOnly: false) { id title message url date read }
}
```

They answer for **the person the request is authenticated as**: requests made with a user's own access token —
an OAuth access token, or a personal access token from their account — where Craft 6 builds the schema for that user
and grants this permission automatically.

A **shared GraphQL token** (Settings → GraphQL → Tokens) isn't a person: even with the permission ticked, its
schema doesn't have these queries, so no token can read anyone else's notifications. Rules, destinations, recipients
and the delivery log aren't exposed over GraphQL at all.
