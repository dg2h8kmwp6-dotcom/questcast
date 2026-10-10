<?php

namespace App\Models;

use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['token'];

    /**
     * JSON columns carry no database default, because MySQL does not allow one.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'stats' => '{}',
        'inventory' => '[]',
        'conditions' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'inventory' => 'array',
            'conditions' => 'array',
            'last_seen_at' => 'datetime',
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

    public function actionFor(Scene $scene): ?Action
    {
        return $this->actions()->where('scene_id', $scene->id)->first();
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }

    public function displayName(): string
    {
        return $this->character_name ?: $this->name;
    }

    public function hasPortrait(): bool
    {
        return filled($this->portrait_path);
    }

    public function portraitUrl(): ?string
    {
        return $this->hasPortrait()
            ? Storage::disk(config('questcast.portraits.disk'))->url($this->portrait_path)
            : null;
    }

    /** Fallback token when somebody declined the camera. */
    public function initials(): string
    {
        return Str::of($this->displayName())->trim()->substr(0, 1)->upper()->value();
    }

    /** D&D-style ability modifier: (score - 10) / 2, rounded down. */
    public function modifierFor(?string $skill): int
    {
        if (! $skill) {
            return 0;
        }

        $score = (int) ($this->stats[strtolower($skill)] ?? 10);

        return (int) floor(($score - 10) / 2);
    }

    public function applyDamage(int $amount): void
    {
        $this->hp = max(0, min($this->max_hp, $this->hp - $amount));
        $this->save();
    }

    public function heal(int $amount): void
    {
        $this->applyDamage(-$amount);
    }

    public function giveItems(array $items): void
    {
        $this->inventory = array_values(array_unique([...$this->inventory, ...$items]));
        $this->save();
    }

    public function takeItems(array $items): void
    {
        $lowered = array_map('strtolower', $items);

        $this->inventory = array_values(array_filter(
            $this->inventory,
            fn ($item) => ! in_array(strtolower($item), $lowered, true),
        ));

        $this->save();
    }
}
