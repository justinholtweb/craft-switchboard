<?php

declare(strict_types=1);

use JustinHoltWeb\Switchboard\Settings;

it('has sensible defaults', function () {
    $settings = new Settings;

    expect($settings->logRetentionDays)->toBe(30);
});
