<?php

namespace App\Support;

/**
 * Every roll in the game happens here, on the server.
 *
 * The DM model is told the result after the fact and is instructed to narrate
 * around it. It never rolls for itself, which is what keeps an improvising
 * language model from quietly deciding that the party always succeeds.
 */
class Dice
{
    public function __construct(private readonly ?int $forced = null) {}

    /** A test harness can pin every d20 to a known value. */
    public static function fixed(int $value): self
    {
        return new self($value);
    }

    public function d20(): int
    {
        return $this->forced ?? random_int(1, 20);
    }

    public function roll(int $sides, int $count = 1): int
    {
        if ($this->forced !== null) {
            return min($this->forced, $sides) * $count;
        }

        return collect(range(1, $count))->sum(fn () => random_int(1, $sides));
    }

    /**
     * Resolve a skill check.
     *
     * A natural 20 always succeeds and a natural 1 always fails, regardless of
     * modifiers or difficulty — the table rule everyone already knows.
     *
     * @return array{roll:int,modifier:int,total:int,success:bool,critical:bool}
     */
    public function check(int $modifier, ?int $dc): array
    {
        $roll = $this->d20();
        $total = $roll + $modifier;
        $critical = $roll === 20 || $roll === 1;

        $success = match (true) {
            $roll === 20 => true,
            $roll === 1 => false,
            $dc === null => true,
            default => $total >= $dc,
        };

        return [
            'roll' => $roll,
            'modifier' => $modifier,
            'total' => $total,
            'success' => $success,
            'critical' => $critical,
        ];
    }
}
