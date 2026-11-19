<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Switchboard's tables: rules (managed in the CP), the delivery log, and a small
 * ledger that keeps one-off triggers (a publish, a scheduled publish) from firing twice.
 *
 * Rules live in the database rather than project config because they hold
 * destinations and signing secrets (encrypted at rest), which don't belong in git.
 */
class Install extends Migration
{
    public function up(): void
    {
        Schema::create('switchboard_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name');
            $table->string('handle', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('trigger', 96)->index();
            $table->json('siteIds')->nullable();
            $table->json('sources')->nullable();
            $table->json('conditions')->nullable();
            $table->json('recipients')->nullable();
            $table->json('channels')->nullable();
            // Ad-hoc destinations (emails, webhook URLs, phone numbers): encrypted JSON.
            $table->text('routes')->nullable();
            $table->text('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('template')->nullable();
            $table->json('payloadFields')->nullable();
            $table->text('signingSecret')->nullable();
            $table->timestamps();
        });

        Schema::create('switchboard_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruleId')->nullable()->constrained('switchboard_rules')->nullOnDelete();
            $table->string('trigger', 96);
            $table->integer('elementId')->nullable();
            $table->integer('siteId')->nullable();
            $table->string('channel', 64);
            // `user:12`, or a masked route (`j***@example.com`, `hooks.slack.com`) — never a full secret URL.
            $table->string('recipient');
            $table->integer('userId')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('subject')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('test')->default(false);
            $table->timestamp('sentAt')->nullable();
            $table->timestamps();
            $table->index(['ruleId', 'created_at']);
        });

        Schema::create('switchboard_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 191)->unique();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switchboard_ledger');
        Schema::dropIfExists('switchboard_deliveries');
        Schema::dropIfExists('switchboard_rules');
    }
}
