<?php

declare(strict_types=1);

use JustinHoltWeb\Switchboard\Dispatch\Conditions;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Dispatch\Renderer;
use JustinHoltWeb\Switchboard\Rules\Rule;

it('confines body templates to _switchboard/', function (string $path, bool $ok) {
    expect(Rule::isTemplatePath($path))->toBe($ok);
})->with([
    ['_switchboard/published.twig', true],
    ['_switchboard/emails/published.blade.php', true],
    ['_switchboard/../index', false],
    ['_switchboard/a/../../secret', false],
    ['_switchboard//a', false],
    ['/_switchboard/a', false],
    ['_switchboard', false],
    ['_switchboardx/a', false],
    ['templates/a.twig', false],
    ['_switchboard/a b', false],
]);

it('evaluates condition operators case-insensitively', function () {
    expect(Conditions::test('Big NEWS today', 'contains', 'news'))->toBeTrue()
        ->and(Conditions::test('Hello', 'equals', 'hello'))->toBeTrue()
        ->and(Conditions::test('Hello', 'notEquals', 'hello'))->toBeFalse()
        ->and(Conditions::test(null, 'empty', ''))->toBeTrue()
        ->and(Conditions::test(' x ', 'notEmpty', ''))->toBeTrue()
        ->and(Conditions::test('abc', 'notContains', 'z'))->toBeTrue()
        ->and(Conditions::test('abc', 'bogus', 'a'))->toBeFalse();
});

it('keys routes without exposing them', function () {
    $key = Rule::routeKey('slack', 'https://hooks.slack.com/services/T/B/SECRET');

    expect($key)->toHaveLength(16)
        ->and($key)->not->toContain('SECRET')
        ->and(Rule::routeKey('discord', 'https://hooks.slack.com/services/T/B/SECRET'))->not->toBe($key);
});

it('masks destinations in the delivery log', function () {
    expect(Dispatcher::mask('mail', 'jane@example.com'))->toBe('j***@example.com')
        ->and(Dispatcher::mask('slack', 'https://hooks.slack.com/services/T/B/SECRET'))->toBe('hooks.slack.com')
        ->and(Dispatcher::mask('sms', '+14155550123'))->toBe('***123')
        ->and(Dispatcher::mask('webhook', '$SWITCHBOARD_HOOK'))->toBe('$SWITCHBOARD_HOOK');
});

it('renders plain text', function () {
    expect(Renderer::text("<p>Hi &amp; <b>bye</b></p>\n\n\n\nEnd"))->toBe("Hi & bye\n\nEnd")
        ->and(Renderer::text("A\n  B\tC", singleLine: true))->toBe('A B C');
});
