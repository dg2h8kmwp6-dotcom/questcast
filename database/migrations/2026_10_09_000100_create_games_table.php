<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();

            // Short, human-shoutable code players type on their phones.
            $table->string('code', 8)->unique();

            // Secret the host browser holds; proves a visitor owns the main screen.
            $table->string('host_token', 64)->unique();

            $table->string('title');
            $table->text('premise');
            $table->string('tone')->default('sunlit');
            $table->string('status', 32)->default('lobby');
            $table->unsignedInteger('scene_number')->default(0);
            $table->string('outcome', 32)->nullable();
            // No database-level default: MySQL forbids defaults on JSON columns.
            // The model supplies the empty default instead.
            $table->json('settings');

            // Set when a scene generation job starts, so a crashed job can be detected.
            $table->timestamp('thinking_since')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
