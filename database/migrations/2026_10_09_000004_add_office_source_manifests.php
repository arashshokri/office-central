<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', fn (Blueprint $table) => $table->json('source_manifest')->nullable());
    }

    public function down(): void
    {
        Schema::table('releases', fn (Blueprint $table) => $table->dropColumn('source_manifest'));
    }
};
