<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();        // sent to Pixelforge as metadata + used in URLs
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Pixelforge side
            $table->string('provider_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();  // reused on every submit retry
            $table->string('status')->index();          // pending|queued|processing|succeeded|failed

            // Request
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money, all integer nano-dollars (1 USD = 1e9)
            $table->unsignedBigInteger('estimated_charge_min');
            $table->unsignedBigInteger('estimated_charge_max');
            $table->unsignedBigInteger('provider_cost')->nullable(); // what Pixelforge charged us
            $table->unsignedBigInteger('charge')->nullable();        // what we charged the user

            // Facts from Pixelforge, kept so every charge can be audited/recomputed
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            // Result
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Submission bookkeeping
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->string('last_submit_error')->nullable();

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index(); // Pixelforge bills by UTC completion day
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();       // evt_..., our duplicate guard
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            $table->json('payload');
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
    }
};
