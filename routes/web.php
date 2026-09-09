<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    /** @var view-string $view */
    $view = 'welcome';

    return view($view);
});
