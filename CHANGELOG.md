# Release Notes for Switchboard

> Switchboard is a **Craft CMS 6** plugin. Craft 6 is in beta: use caution on production.

## 6.0.0-beta.1 - Unreleased

### Added
- Notification rules, managed in the control panel (Switchboard → Rules): a trigger, sites, sources, conditions, recipients, channels, ad-hoc destinations and a message.
- Triggers: an entry is published (including scheduled entries, via `craft:switchboard:scan`), a live entry is updated, an entry is deleted, a user account is created or activated, an asset is uploaded, a queued job fails, and custom triggers fired with `Switchboard::fire()`.
- Channels: the notification bell (Craft 6's control panel notifications, plus a front-end bell), email, broadcast, Slack, Discord, Microsoft Teams, signed JSON webhooks, and SMS through Vonage or Twilio when installed. Any other Laravel notification channel can be added with the `ChannelTypesResolving` event.
- Every delivery is a queued Laravel notification with retries, recorded in the delivery log (Switchboard → Deliveries); failures are counted on the sidebar badge.
- Recipients are permission-checked: only active users who can view the element and access its site are notified; failed-job notifications reach admins only.
- Messages are rendered per recipient from a snapshot that leaves out fields behind user conditions, related elements they can't view and personal details; ad-hoc destinations are treated as anonymous.
- Per-recipient languages: a user's preferred language, else the site's. English and French default messages.
- Subjects and bodies in sandboxed Twig; admin-only body templates in Twig or Blade from `templates/_switchboard/`.
- SSRF-safe outbound requests: public HTTPS on port 443 only, every resolved address checked and pinned, no redirects, short timeouts; an `allowedHosts` setting for development receivers.
- Webhooks signed with a per-rule secret (`Switchboard-Signature`); destinations encrypted at rest, optionally `$SWITCHBOARD_*` environment variables, never stored in queued jobs, masked in the log.
- Front-end bell for Twig and Blade (`switchboard.notifications()`, `unreadCount()`, `markReadUrl()`, `feedUrl()`), a JSON feed and a CSRF-protected mark-as-read action.
- GraphQL: `switchboardNotifications` and `switchboardUnreadCount` for requests made with a user's own access token.
- Console: `craft:switchboard:rules`, `craft:switchboard:test`, `craft:switchboard:scan`, `craft:switchboard:prune`.
- Permissions: “Manage notification rules” (sources they can view; no destinations or templates) and “View the delivery log” (deliveries about elements they can view).
- Sidebar label and icon settings.
