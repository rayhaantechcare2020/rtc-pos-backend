<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});



require __DIR__.'/auth.php';
