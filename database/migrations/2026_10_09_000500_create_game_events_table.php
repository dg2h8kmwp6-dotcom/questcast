<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->text('body');
            $table->json('meta');
            $table->timestamps();

            $table->index(['game_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
