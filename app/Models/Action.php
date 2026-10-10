<?php

namespace App\Models;

use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Action extends Model
{
    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'critical' => 'boolean',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** What the player actually chose to do, as one line of prose. */
    public function intent(): string
    {
        if ($this->free_text) {
            return $this->free_text;
        }

        $option = $this->scene?->optionFor((string) $this->option_key);

        return $option['label'] ?? 'Hesitates, doing nothing.';
    }

    /** The roll, written the way a DM would say it out loud. */
    public function rollSummary(): ?string
    {
        if ($this->roll === null) {
            return null;
        }

        $sign = $this->modifier >= 0 ? '+' : '-';
        $line = "d20 {$this->roll} {$sign} ".abs($this->modifier)." = {$this->total}";

        if ($this->dc !== null) {
            $line .= ' vs DC '.$this->dc.' — '.($this->success ? 'success' : 'failure');
        }

        if ($this->critical) {
            $line .= $this->roll === 20 ? ' (critical success!)' : ' (critical failure!)';
        }

        return $line;
    }
}
