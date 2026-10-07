<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            // Legacy licenses retain their original API behavior.
            $table->string('activation_mode')->default('legacy');
            $table->timestamp('consumed_at')->nullable();
            $table->json('deployment_config')->nullable();
        });
        Schema::table('installations', function (Blueprint $table): void {
            $table->text('device_public_key')->nullable();
            $table->uuid('client_request_id')->nullable()->unique();
            $table->unsignedBigInteger('agent_sequence')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('deployment_receipt')->nullable();
            $table->uuid('reactivated_from')->nullable();
        });
        Schema::table('releases', function (Blueprint $table): void {
            $table->json('runtime_manifest')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('releases', fn (Blueprint $t) => $t->dropColumn('runtime_manifest'));
        Schema::table('installations', fn (Blueprint $t) => $t->dropColumn([
            'device_public_key', 'client_request_id', 'agent_sequence', 'completed_at', 'deployment_receipt', 'reactivated_from',
        ]));
        Schema::table('licenses', fn (Blueprint $t) => $t->dropColumn(['activation_mode', 'consumed_at', 'deployment_config']));
    }
};
