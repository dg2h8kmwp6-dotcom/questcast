<?php

namespace App\Models;

use App\Enums\SceneStatus;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * JSON columns carry no database default, because MySQL does not allow one.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'options' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'status' => SceneStatus::class,
            'options' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    public function optionFor(string $key): ?array
    {
        return collect($this->options)->firstWhere('key', $key);
    }

    public function isOpen(): bool
    {
        return $this->status === SceneStatus::Open;
    }
}
