<?php

namespace App\Models;

use App\Enums\SceneStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    /** Where the storyteller's reading of this scene lives, once there is one. */
    public function narrationAudioUrl(): ?string
    {
        return $this->audioUrl($this->narration_audio_path);
    }

    public function resolutionAudioUrl(): ?string
    {
        return $this->audioUrl($this->resolution_audio_path);
    }

    /**
     * A link the browser can actually fetch.
     *
     * Object storage buckets are private by default and nothing in the writing
     * path asks otherwise, so a plain URL is a 403 the room never sees: the
     * audio element fails and the story just stays quiet. A signed link works
     * whether the bucket is public or not, so it is the one we prefer and the
     * plain URL is only the fallback for disks that cannot sign at all.
     */
    private function audioUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk(config('questcast.narrator.disk'));

        try {
            return $disk->temporaryUrl($path, self::linkExpiry());
        } catch (Throwable) {
            return $disk->url($path);
        }
    }

    /**
     * A signing deadline that only moves once an hour.
     *
     * Signing against "now" would mint a different URL every time the board
     * re-draws — which it does on every broadcast and every ten-second poll —
     * churning the attribute the host screen reads and defeating browser
     * caching of audio it already has. Rounding holds the link steady.
     */
    private static function linkExpiry(): CarbonInterface
    {
        return Carbon::createFromTimestamp((int) ceil(time() / 3600) * 3600)
            ->addHours(6);
    }
}
