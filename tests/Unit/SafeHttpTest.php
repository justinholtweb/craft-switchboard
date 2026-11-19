<?php

declare(strict_types=1);

use JustinHoltWeb\Switchboard\Channels\HttpChannel;
use JustinHoltWeb\Switchboard\Channels\SafeHttp;

it('accepts only globally routable addresses', function (string $ip, bool $public) {
    expect(SafeHttp::isPublicIp($ip))->toBe($public);
})->with([
    ['1.1.1.1', true],
    ['8.8.8.8', true],
    ['2606:4700:4700::1111', true],
    ['127.0.0.1', false],
    ['127.10.0.1', false],
    ['10.0.0.1', false],
    ['172.16.5.4', false],
    ['192.168.0.10', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['198.18.0.1', false],
    ['224.0.0.251', false],
    ['255.255.255.255', false],
    ['::1', false],
    ['::', false],
    ['fe80::1', false],
    ['fd12:3456::1', false],
    ['ff02::1', false],
    ['::ffff:127.0.0.1', false],
    ['::ffff:10.1.2.3', false],
    ['::ffff:8.8.8.8', true],
    ['64:ff9b::7f00:1', false],
    ['2002:7f00:1::', false],
    ['not-an-ip', false],
]);

it('matches service hosts exactly or by subdomain wildcard', function () {
    expect(SafeHttp::hostMatches('hooks.slack.com', ['hooks.slack.com']))->toBeTrue()
        ->and(SafeHttp::hostMatches('evilhooks.slack.com', ['hooks.slack.com']))->toBeFalse()
        ->and(SafeHttp::hostMatches('prod-01.westus.logic.azure.com', ['*.logic.azure.com']))->toBeTrue()
        ->and(SafeHttp::hostMatches('logic.azure.com.evil.test', ['*.logic.azure.com']))->toBeFalse()
        ->and(SafeHttp::hostMatches('evillogic.azure.com', ['*.logic.azure.com']))->toBeFalse();
});

it('reads only whole-value SWITCHBOARD_ environment references', function () {
    expect(SafeHttp::isEnvReference('$SWITCHBOARD_SLACK_URL'))->toBeTrue()
        ->and(SafeHttp::isEnvReference('$DB_PASSWORD'))->toBeFalse()
        ->and(SafeHttp::isEnvReference('https://x.test/${SWITCHBOARD_A}'))->toBeFalse()
        ->and(SafeHttp::isEnvReference('$SWITCHBOARD_A/extra'))->toBeFalse();
});

it('signs webhook bodies and verifies them with a tolerance', function () {
    $header = HttpChannel::signatureHeaders('{"a":1}', 'whsec_test', 1_700_000_000)['Switchboard-Signature'];

    expect($header)->toMatch('/^t=1700000000,v1=[a-f0-9]{64}$/')
        ->and(HttpChannel::verify('{"a":1}', $header, 'whsec_test', now: 1_700_000_100))->toBeTrue()
        ->and(HttpChannel::verify('{"a":2}', $header, 'whsec_test', now: 1_700_000_100))->toBeFalse()
        ->and(HttpChannel::verify('{"a":1}', $header, 'other', now: 1_700_000_100))->toBeFalse()
        ->and(HttpChannel::verify('{"a":1}', $header, 'whsec_test', now: 1_700_001_000))->toBeFalse();
});
