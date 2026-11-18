<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Today is the home screen after login (D-038). Old /dashboard links land there.
    Route::livewire('today', 'pages::today.index')->name('today');
    Route::redirect('dashboard', 'today')->name('dashboard');

    Route::livewire('tasks', 'pages::tasks.index')->name('tasks.index');

    // Finance: one sidebar link, four tabs (D-041).
    Route::livewire('finance', 'pages::finance.overview')->name('finance.overview');
    Route::livewire('finance/accounts', 'pages::finance.accounts')->name('finance.accounts');
    Route::livewire('finance/transactions', 'pages::finance.transactions')->name('finance.transactions');
    Route::livewire('finance/debts', 'pages::finance.debts')->name('finance.debts');

    // Personal Care: one sidebar link, tabs (D-045).
    Route::livewire('care', 'pages::care.products')->name('care.products');
    Route::livewire('care/spending', 'pages::care.spending')->name('care.spending');
});

require __DIR__.'/settings.php';
