---
title: Channels
slug: channels
order: 30
summary: The bell, email, broadcast, Slack, Discord, Teams, webhooks, SMS — and adding any Laravel notification channel.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Every delivery is a queued Laravel notification, retried three times (after 10 s and 60 s) and logged.

## For Craft users

| Channel | What happens |
|---|---|
| **Notification bell** | Laravel's `database` channel, in the shape of Craft 6's own control panel notifications: it appears in the **CP bell** and in your [front-end bell](bell). |
| **Email** | A Laravel `MailMessage` to the user's email address, through Craft's mail settings: the subject, the body as paragraphs, a button to the element. |
| **Broadcast** | Laravel's `broadcast` channel, to the user's private channel (`switchboard.notification` event). Needs a broadcasting connection. |

## Ad-hoc destinations (admins)

| Channel | Destination | Body |
|---|---|---|
| **Email** | an address | as above |
| **Slack** | an incoming-webhook URL on `hooks.slack.com` | `{"text": …}` in Slack mrkdwn, escaped |
| **Discord** | a webhook URL on `discord.com` | `{"content": …}` with mentions disabled (no `@everyone` from content) |
| **Microsoft Teams** | a Workflows (Power Automate) webhook URL | an Adaptive Card |
| **Webhook** | any public HTTPS URL | signed JSON — see [Webhooks](webhooks) |
| **SMS** | a phone number (`+14155550123`) | subject, body and link, up to 1,000 characters |

A destination can be an environment variable named `$SWITCHBOARD_…` (e.g. `$SWITCHBOARD_SLACK_URL`), so the URL
stays out of the database. Other variables can't be read.

## SMS

Install one of Laravel's SMS channel packages and configure it as usual; Switchboard picks it up:

- `composer require laravel/vonage-notification-channel` (Vonage), or
- `composer require laravel-notification-channels/twilio` (Twilio)

> SMS delivery hasn't been verified against live Vonage or Twilio accounts in this beta.

## Any other channel

Laravel has [dozens of community channels](https://laravel-notification-channels.com). Register one as a
`ChannelType` from the `ChannelTypesResolving` event; its `message` closure builds the package's message:

```php
use JustinHoltWeb\Switchboard\Channels\ChannelType;
use JustinHoltWeb\Switchboard\Events\ChannelTypesResolving;
use JustinHoltWeb\Switchboard\Notifications\Message;

Event::listen(ChannelTypesResolving::class, function (ChannelTypesResolving $e) {
    $e->channels['telegram'] = new ChannelType(
        key: 'telegram', label: 'Telegram', forUsers: false, forRoutes: true,
        via: \NotificationChannels\Telegram\TelegramChannel::class,
        validate: fn (string $to) => ctype_digit(ltrim($to, '-')) ? null : 'Enter a chat ID.',
        message: fn (Message $m) => \NotificationChannels\Telegram\TelegramMessage::create($m->subject."\n".$m->body),
    );
});
```

`Message` has `subject`, `body`, `url` and `payload` — already rendered for that recipient.
