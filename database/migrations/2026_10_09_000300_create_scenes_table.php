<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 32)->default('generating');

            // What the host screen reads aloud to the room.
            $table->text('narration')->nullable();

            // Choices pushed to every phone: [{key,label,skill,dc}, ...]
            $table->json('options');

            // Outcome narration, written once the party's actions are resolved.
            $table->text('resolution')->nullable();

            $table->string('image_prompt')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
