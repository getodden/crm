<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The schedule the open packages need (see docs/installation.md). The same frequencies Odden Cloud uses.

// Sales
Schedule::command('sales:process-cadences')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sales:expire-quotes')->dailyAt('01:00')->withoutOverlapping();
