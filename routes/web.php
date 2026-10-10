<?php

use App\Livewire\Home;
use App\Livewire\Host\Screen;
use App\Livewire\Player\Table;
use Illuminate\Support\Facades\Route;

Route::get('/', Home::class)->name('home');

// The main screen: the computer running the game.
Route::get('/host/{game:code}', Screen::class)->name('host');

// A phone at the table. Deliberately short, because people type it in.
Route::get('/play/{game:code}', Table::class)->name('play');
