<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['app' => 'ksef-foundry laravel example', 'try' => '/ksef/limits (needs "Authorization: Bearer $EXAMPLE_API_TOKEN")']);
