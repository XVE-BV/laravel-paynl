<?php

use Illuminate\Support\Facades\Route;
use Xve\LaravelPaynl\Http\Controllers\ExchangeController;

if (config('paynl.exchange.enabled')) {
    Route::match(['get', 'post'], config('paynl.exchange.path'), ExchangeController::class)
        ->middleware(config('paynl.exchange.middleware'))
        ->name('paynl.exchange');
}
