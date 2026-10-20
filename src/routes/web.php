<?php

use Illuminate\Support\Facades\Route;

use MM\Meros\Crm\App\Middleware\AuthenticatePortal;
use MM\Meros\Crm\App\Middleware\RedirectIfAuthenticatedPortal;

/*
|--------------------------------------------------------------------------
| Portal Routes
|--------------------------------------------------------------------------
*/

Route::middleware('web')
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {

        // ---- Guest only --------------------------------------------------
        Route::middleware(RedirectIfAuthenticatedPortal::class)->group(function () {
            Route::livewire('login', 'meros_crm::portal-login')
                ->name('login');
        });

        // ---- Authenticated only -----------------------------------------
        Route::middleware(AuthenticatePortal::class)->group(function () {
            Route::livewire('/', 'meros_crm::portal')
                ->name('dashboard');
        });
    });