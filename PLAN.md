# Switchboard — plan

Route Craft events to any Laravel notification channel — Slack, SMS, Discord, Teams, mail — with rules you build in the CP.

- Package: `justinholtweb/craft-switchboard` · handle `switchboard` · namespace `JustinHoltWeb\Switchboard`
- Editions: single edition · pricing: Free (Pro TBD) (provisional)
- Laravel integration: Laravel notifications — Any Laravel notification channel: mail, Slack, Vonage SMS, database, broadcast, and community channels.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

## Phases
1. **Rules.** Trigger (entry published/updated/deleted, user registered/activated, asset uploaded, Contact Form
   submitted, scheduled), condition builder, recipients (users, groups, ad hoc routes per channel), channels.
2. **Channels.** Mail, Slack (webhook + bot), Vonage, database, broadcast built in; any installed
   `laravel-notification-channels/*` package auto-detected.
3. **Messages.** Twig message templates per channel with element variables; test-send button.
4. **Delivery log.** Queued sends, retries, per-rule log, failures surfaced in the CP.

## Risks / open questions
- Overlap with Schedulr (scheduled notifications) — Schedulr could become a Switchboard channel consumer.

## Definition of done (every phase)
`bin/port-check craft-switchboard` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
