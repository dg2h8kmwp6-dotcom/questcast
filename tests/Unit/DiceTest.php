<?php

use App\Support\Dice;

it('stays within the faces of the die', function () {
    $dice = new Dice;

    foreach (range(1, 200) as $ignored) {
        expect($dice->d20())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(20);
    }
});

it('beats the difficulty when the total clears it', function () {
    $result = Dice::fixed(15)->check(modifier: 2, dc: 16);

    expect($result)
        ->roll->toBe(15)
        ->total->toBe(17)
        ->success->toBeTrue()
        ->critical->toBeFalse();
});

it('fails when the total falls short', function () {
    expect(Dice::fixed(5)->check(modifier: 1, dc: 14))
        ->success->toBeFalse();
});

it('treats a natural twenty as a success however steep the difficulty', function () {
    expect(Dice::fixed(20)->check(modifier: -5, dc: 30))
        ->success->toBeTrue()
        ->critical->toBeTrue();
});

it('treats a natural one as a failure however generous the modifier', function () {
    expect(Dice::fixed(1)->check(modifier: 99, dc: 2))
        ->success->toBeFalse()
        ->critical->toBeTrue();
});

it('succeeds by default when no difficulty is set', function () {
    expect(Dice::fixed(7)->check(modifier: 0, dc: null))
        ->success->toBeTrue();
});
