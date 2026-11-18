<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Today is the home screen after login (D-038). Old /dashboard links land there.
    Route::livewire('today', 'pages::today.index')->name('today');
    Route::redirect('dashboard', 'today')->name('dashboard');

    Route::livewire('tasks', 'pages::tasks.index')->name('tasks.index');
});

require __DIR__.'/settings.php';
