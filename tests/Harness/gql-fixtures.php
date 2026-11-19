<?php

// Switchboard harness: a shared GraphQL token whose schema includes “Read your own notifications”
// (token "sbbell" padded to 32), and personal access tokens (OAuth JWTs) for two fixture users.
// Prints {"editor": "<jwt>", "editor2": "<jwt>"}. Needs Passport keys (check.sh creates them if missing).
// Run with: ddev artisan tinker --execute "require 'storage/switchboard-gql-fixtures.php';"

use CraftCms\Cms\Auth\PersonalAccessTokens\PersonalAccessTokens;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Data\GqlToken;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;

$schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === 'switchboard_bell') ?? new GqlSchema;
$schema->name = 'switchboard_bell';
$schema->scope = ['sites.'.Sites::getPrimarySite()->uid.':read', 'switchboard.notifications:read'];
Gql::saveSchema($schema) || throw new RuntimeException(json_encode($schema->errors()->all()));

$token = collect(Gql::getTokens())->first(fn ($t) => $t->name === 'switchboard_bell') ?? new GqlToken;
$token->name = 'switchboard_bell';
$token->schemaId = $schema->id;
$token->accessToken = str_pad('sbbell', 32, '0');
Gql::saveToken($token) || throw new RuntimeException(json_encode($token->errors()->all()));
Gql::invalidateCaches();

$jwt = fn (string $username) => app(PersonalAccessTokens::class)->create(
    User::find()->username($username)->one(), 'switchboard harness', ['graphql:read'], new DateInterval('PT1H'),
);

echo json_encode(['editor' => $jwt('switchboard_editor'), 'editor2' => $jwt('switchboard_editor2')]);
