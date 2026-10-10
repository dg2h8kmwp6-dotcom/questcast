<?php

use App\Models\Player;

it('derives ability modifiers the way the table does', function (int $score, int $expected) {
    $player = Player::factory()->makeOne(['stats' => ['strength' => $score]]);

    expect($player->modifierFor('strength'))->toBe($expected);
})->with([
    [8, -1],
    [10, 0],
    [11, 0],
    [14, 2],
    [17, 3],
    [20, 5],
]);

it('has no modifier for an unknown or absent skill', function () {
    $player = Player::factory()->makeOne(['stats' => []]);

    expect($player->modifierFor('strength'))->toBe(0);
    expect($player->modifierFor(null))->toBe(0);
});

it('never drops below zero or heals past the maximum', function () {
    $player = Player::factory()->createOne(['max_hp' => 10, 'hp' => 4]);

    $player->applyDamage(99);
    expect($player->fresh()->hp)->toBe(0);

    $player->heal(99);
    expect($player->fresh()->hp)->toBe(10);
});

it('takes items back regardless of how they were capitalised', function () {
    $player = Player::factory()->createOne(['inventory' => ['Brass Key', 'rope']]);

    $player->takeItems(['brass key']);

    expect($player->fresh()->inventory)->toBe(['rope']);
});
