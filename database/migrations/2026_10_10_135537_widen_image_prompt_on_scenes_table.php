<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * image_prompt holds whatever the storyteller writes, so it cannot be 255.
     *
     * SQLite ignores varchar lengths, so this only ever failed on MySQL — and
     * it failed hard: a prompt one character too long aborted the whole round
     * with "Data too long for column", losing the resolution the party had
     * already earned and leaving the host to reload.
     */
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->text('image_prompt')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('image_prompt')->nullable()->change();
        });
    }
};
