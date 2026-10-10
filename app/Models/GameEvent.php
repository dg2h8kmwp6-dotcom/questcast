<?php

namespace App\Models;

use App\Enums\EventType;
use Database\Factories\GameEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameEvent extends Model
{
    /** @use HasFactory<GameEventFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * JSON columns carry no database default, because MySQL does not allow one.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'meta' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'meta' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }
}
