<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', fn (Blueprint $table) => $table->text('license_key_encrypted')->nullable());
        Schema::table('products', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('licenses', fn (Blueprint $table) => $table->dropColumn('license_key_encrypted'));
        Schema::table('products', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
