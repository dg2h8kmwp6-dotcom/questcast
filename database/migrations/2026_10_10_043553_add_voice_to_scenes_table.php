<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the spoken version of each passage lives on the narration disk.
     *
     * Both are nullable and stay null until the queue gets to them, so a scene
     * is complete and playable the moment it is written, with or without a
     * voice. Adding them touches no existing row.
     */
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('narration_audio_path')->nullable()->after('narration');
            $table->string('resolution_audio_path')->nullable()->after('resolution');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn(['narration_audio_path', 'resolution_audio_path']);
        });
    }
};
