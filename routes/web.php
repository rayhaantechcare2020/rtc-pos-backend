<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['RTC_LTD POS App version' => app()->version()];
});



require __DIR__.'/auth.php';
