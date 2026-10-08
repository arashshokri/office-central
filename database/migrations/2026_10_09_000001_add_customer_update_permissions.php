<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('licenses', fn (Blueprint $table) => $table->foreignId('update_release_id')->nullable()->constrained('releases')->nullOnDelete());
        Schema::table('releases', fn (Blueprint $table) => $table->boolean('is_security')->default(false));
    }
    public function down(): void {
        Schema::table('licenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('update_release_id'));
        Schema::table('releases', fn (Blueprint $table) => $table->dropColumn('is_security'));
    }
};
