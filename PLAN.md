# Switchboard — plan

Route Craft events to any Laravel notification channel — Slack, SMS, Discord, Teams, mail — with rules you build in the CP.

- Package: `justinholtweb/craft-switchboard` · handle `switchboard` · namespace `JustinHoltWeb\Switchboard`
- Editions: single edition · pricing: **Free** (Craft License)
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

## Status (2026-11-18, overnight)

Phases 1–4 are built and verified in the harness (`tests/Harness/check.sh`), plus GraphQL, multi-site and
translations, Twig + Blade, icon and docs.

1. **Rules** — done. Triggers: entry published (+ scheduled scan) / updated / deleted, user registered /
   activated, asset uploaded, queue job failed, `custom:<name>`. Sources, conditions, recipients (users, groups,
   admins, authors, subject user), ad-hoc routes. Contact Form trigger not built (see questions).
2. **Channels** — done: mail, CP bell (core `CpNotification` shape, so it's in Craft's bell), broadcast, Slack,
   Discord, Teams (Adaptive Card), signed webhooks, SMS (Vonage/Twilio adapters, **unverified** — no accounts or
   packages in the harness), extension event for any channel package. Slack/Discord/Teams verified against a local
   sink only (no real workspaces).
3. **Messages** — done: sandboxed Twig subject/body, Twig/Blade file templates (admin-only, `_switchboard/`),
   per-recipient snapshot, per-recipient language, test send (CP + console).
4. **Delivery log** — done: queued notifications with 3 tries, per-rule log, failures badge, pruning.

Security review fixes (coordinator, 2026-11-18): conditions only read anonymously visible fields; test sends ignore
conditions and answer uniformly; non-admins can't change rules with destinations, can't set templates, and only
build rules on sources they can view; delivery log filtered for non-admins; templates confined to `_switchboard/`.

Broadcast is only checked to be delivered through Laravel's broadcaster (the harness's `log` connection); the
browser side is Walkie's job.

## Questions for Justin

1. **Rules in the database, not project config.** They hold destinations and signing secrets (encrypted with the
   app key), which shouldn't be in git. That means rules don't deploy between environments. OK, or do you want
   project config for everything except destinations/secrets (and an export/import command)?
2. **Contact Form / Formie triggers:** no Craft 6 ports exist yet to hook into. Custom triggers
   (`Switchboard::fire()`) cover it for now — build native ones when those plugins land?
3. **SMS for Craft users** (a phone-number field on users) isn't built; SMS goes to ad-hoc numbers only. Wanted?
4. **“Published” is once per entry, site and post date** (unpublish → republish with the same post date doesn't
   notify again). That avoids duplicates from propagation and the scheduled scan. OK?
5. **Digests / scheduled summaries** (PLAN's “scheduled” trigger, and the Schedulr overlap) aren't built. Worth a v2?
6. **GraphQL is deliberately thin**: only the token user's own bell notifications (user-owned OAuth/personal access
   tokens). Rules and the delivery log aren't exposed. OK?
7. The harness had no Passport keys; check.sh ran `php artisan passport:keys` (under the lock) so GraphQL personal
   access tokens could be tested. Keep them, or should checks avoid creating them?
8. Site accent for the marketing site: **#6A7BA0** (slate blue, lifted from the icon's #596071 so it reads on the
   dark background and carries the shared white-text button). OK?
