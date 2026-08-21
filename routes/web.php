<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/menu/{branchCode}/{tableCode}', function (string $branchCode, string $tableCode) {
    return view('public.qr-menu', [
        'branchCode' => strtoupper($branchCode),
        'tableCode' => strtoupper($tableCode),
    ]);
});

Route::get('/menu/{tableCode}', function (string $tableCode) {
    return view('public.qr-menu', [
        'branchCode' => null,
        'tableCode' => strtoupper($tableCode),
    ]);
});
