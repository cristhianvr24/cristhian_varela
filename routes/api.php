<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::post('easy-money', [PaymentController::class, 'processEasyMoney']);
Route::post('super-walletz', [PaymentController::class, 'processSuperWalletz']);
Route::post('super-walletz/webhook', [PaymentController::class, 'handleSuperWalletzWebhook']);

