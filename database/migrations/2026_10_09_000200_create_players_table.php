<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Secret held in the phone's session; the only identity a player has.
            $table->string('token', 64)->unique();

            $table->string('name');
            $table->string('character_name')->nullable();
            $table->string('archetype', 64)->nullable();

            $table->unsignedSmallInteger('max_hp')->default(10);
            $table->smallInteger('hp')->default(10);

            // Defaults live on the model; MySQL forbids defaults on JSON columns.
            $table->json('stats');
            $table->json('inventory');
            $table->json('conditions');

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
