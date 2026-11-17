# Switchboard

Route Craft events to any Laravel notification channel — Slack, SMS, Discord, Teams, mail — with rules you build in the CP.

> **Status:** scaffold — nothing user-facing yet. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: Laravel notifications. Any Laravel notification channel: mail, Slack, Vonage SMS, database, broadcast, and community channels.

## Editions and pricing

single edition — Free (Pro TBD) (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-switchboard --install`).
