---
title: Troubleshooting
slug: troubleshooting
order: 120
summary: Reading the delivery log and fixing common failures.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

| In the delivery log | Meaning |
|---|---|
| `skipped` — can't view this element | the user lacks view permission for that section, volume or user |
| `skipped` — no access to this site | multi-site: no “Access the site” permission |
| `skipped` — not an active user | pending, suspended, locked or deactivated |
| `failed` — Template error | the rule's subject/body or template threw; the message says where. Sandboxed Twig refuses `craft.*`. |
| `failed` — only https URLs on port 443 | the destination is `http` or on another port (allow it in `allowedHosts` for development) |
| `failed` — resolves to a private or reserved address | SSRF protection: the host points inside your network |
| `failed` — host answered HTTP 3xx/4xx/5xx | the receiver refused it; redirects aren't followed |
| `failed` — environment variable is empty | a `$SWITCHBOARD_…` destination isn't set |
| `sending` ×1 | the first try failed and a retry is waiting (10 s, then 60 s) |

Mail problems show the mailer's error; check Settings → Email and send yourself a test with **Send test to me**.
