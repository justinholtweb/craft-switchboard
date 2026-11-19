---
title: Webhooks
slug: webhooks
order: 60
summary: The JSON body, verifying the signature, and the SSRF protections on every outbound request.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## The request

`POST` with `Content-Type: application/json` and these headers:

| Header | |
|---|---|
| `Switchboard-Signature` | `t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">` |
| `Switchboard-Event` | e.g. `entry.published` |
| `Switchboard-Delivery` | the delivery's ID (also `id` in the body) — use it to ignore retries you've already handled |

```json
{
  "id": "812", "event": "entry.published", "subject": "Published: Hello", "message": "“Hello” was published on Acme.",
  "url": "https://acme.test/news/hello", "sentAt": "2026-11-19T02:43:39+00:00",
  "rule": {"handle": "newsPublished", "name": "News published"}, "site": "default",
  "element": {"id": 9796, "kind": "entry", "title": "Hello", "status": "live", "url": "…", "section": "news", "fields": {"summary": "…"}},
  "data": {}
}
```

## Verifying

The secret is on the rule (admins only), with a button to generate a new one.

```php
[$t, $v1] = sscanf($request->header('Switchboard-Signature'), 't=%d,v1=%s');
$ok = abs(time() - $t) < 300 && hash_equals(hash_hmac('sha256', $t.'.'.$request->getContent(), $secret), $v1);
```

## SSRF protection

Every webhook, Slack, Discord and Teams request:

- must be `https` on port 443, with no username or password in the URL;
- is resolved once; **every** address must be globally routable — loopback, private (RFC 1918, ULA), link-local
  (including `169.254.169.254`), CGNAT, multicast, reserved, and IPv4-mapped / NAT64 / 6to4 forms of those are refused;
- is pinned to the address that was checked, so DNS can't change between the check and the request;
- never follows redirects (a 3xx is a failure), and gives up after the `webhookTimeout` (5 s by default);
- for Slack, Discord and Teams, must be on that service's own hosts.

Failures are retried three times; errors in the log name the host only, never the path (which is often the secret).

For a receiver on your own network in development, list it in `config/craft/switchboard.php`:

```php
return ['allowedHosts' => ['127.0.0.1:8080', 'hooks.internal.test']];
```

A `host:port` entry allows that port only. Leave the list empty in production.
