<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\GameStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /** Ambiguous glyphs are left out so a code is readable across a room. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $guarded = [];

    /**
     * JSON columns carry no database default, because MySQL does not allow one.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'settings' => 'array',
            'thinking_since' => 'datetime',
        ];
    }

    public static function start(string $title, string $premise, string $tone = 'sunlit'): self
    {
        return self::create([
            'code' => self::freshCode(),
            'host_token' => Str::random(64),
            'title' => $title,
            'premise' => $premise,
            'tone' => $tone,
            'status' => GameStatus::Lobby,
            'settings' => config('questcast.defaults'),
        ]);
    }

    public static function freshCode(): string
    {
        do {
            $code = collect(range(1, 5))
                ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function livingPlayers(): HasMany
    {
        return $this->players()->where('hp', '>', 0);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('number');
    }

    public function currentScene(): HasOne
    {
        return $this->hasOne(Scene::class)->latestOfMany('number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function log(EventType $type, string $body, array $meta = [], ?Scene $scene = null): GameEvent
    {
        return $this->events()->create([
            'scene_id' => $scene?->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
        ]);
    }

    public function transitionTo(GameStatus $status): void
    {
        $this->update([
            'status' => $status,
            'thinking_since' => in_array($status, [GameStatus::Generating, GameStatus::Resolving], true)
                ? now()
                : null,
        ]);
    }

    /** True once every living player has locked in an action for the open scene. */
    public function partyHasDecided(): bool
    {
        $scene = $this->currentScene;

        if (! $scene) {
            return false;
        }

        $living = $this->livingPlayers()->pluck('id');

        if ($living->isEmpty()) {
            return false;
        }

        $decided = $scene->actions()->whereIn('player_id', $living)->count();

        return $decided >= $living->count();
    }

    public function isStuck(): bool
    {
        return $this->thinking_since !== null
            && $this->thinking_since->lt(now()->subSeconds(config('questcast.dm.stuck_after_seconds')));
    }
}
