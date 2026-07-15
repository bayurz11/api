<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/menu/{tableCode}', function (string $tableCode) {
    return view('public.qr-menu', [
        'tableCode' => strtoupper($tableCode),
    ]);
});
