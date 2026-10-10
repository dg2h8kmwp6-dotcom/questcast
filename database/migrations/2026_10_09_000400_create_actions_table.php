<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();

            // Either a pushed option key, or something the player typed, or both.
            $table->string('option_key', 32)->nullable();
            $table->text('free_text')->nullable();

            // Dice are rolled on the server the moment an action is locked in, so
            // the DM is told the result and can never invent a different one.
            $table->string('skill', 32)->nullable();
            $table->unsignedSmallInteger('dc')->nullable();
            $table->unsignedSmallInteger('roll')->nullable();
            $table->smallInteger('modifier')->default(0);
            $table->smallInteger('total')->nullable();
            $table->boolean('success')->nullable();
            $table->boolean('critical')->default(false);

            $table->timestamps();

            // One action per player per scene; re-submitting replaces it.
            $table->unique(['scene_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
