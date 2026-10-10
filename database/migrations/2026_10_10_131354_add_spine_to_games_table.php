<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The story spine: beats laid out before the party ever acts.
     *
     * "spine" is written once and never edited, because it is read into the
     * cached system prompt and a change there would re-bill the whole session.
     * Consumption is tracked beside it instead. Both are additive and carry
     * defaults, so a game already in progress is untouched and simply has no
     * safety net.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->json('spine')->nullable()->after('tone');
            $table->unsignedInteger('spine_used')->default(0)->after('spine');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['spine', 'spine_used']);
        });
    }
};
