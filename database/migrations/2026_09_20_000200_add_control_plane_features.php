<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('state_revision')->default(1)->after('status');
            $table->timestamp('temporarily_locked_at')->nullable()->after('expires_at');
            $table->timestamp('temporarily_unlocked_at')->nullable()->after('temporarily_locked_at');
            $table->string('temporary_lock_message', 500)->nullable()->after('temporarily_unlocked_at');
            $table->foreignId('temporary_locked_by')->nullable()->after('temporary_lock_message')->constrained('users')->nullOnDelete();
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->timestamp('last_state_synced_at')->nullable()->after('last_seen_at')->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('active');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::table('repository_integrations', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('release_channel')->default('stable')->after('branch');
            $table->boolean('auto_publish')->default(false)->after('enabled');
            $table->text('last_error')->nullable()->after('last_commit');
        });

        Schema::create('agent_state_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('state_revision')->index();
            $table->string('access_state', 20)->index();
            $table->json('payload');
            $table->text('signature');
            $table->timestamp('issued_at')->index();
            $table->timestamps();
            $table->unique(['installation_id', 'state_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_state_snapshots');

        Schema::table('repository_integrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['release_channel', 'auto_publish', 'last_error']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });

        Schema::table('installations', function (Blueprint $table): void {
            $table->dropIndex(['last_state_synced_at']);
            $table->dropColumn('last_state_synced_at');
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('temporary_locked_by');
            $table->dropColumn(['state_revision', 'temporarily_locked_at', 'temporarily_unlocked_at', 'temporary_lock_message']);
        });
    }
};
