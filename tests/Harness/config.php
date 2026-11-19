<?php

// Switchboard harness config (copied to the harness as config/craft/switchboard.php by check.sh).
// Its own queue, so checks run only Switchboard's jobs; the local webhook sink allowed.
return [
    'queue' => 'switchboard',
    'allowedHosts' => ['127.0.0.1:18093'],
    'webhookTimeout' => 3,
];
