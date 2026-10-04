<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The schedule the packages need (see docs/installation.md). The same frequencies Odden Cloud uses.

// Marketing automation and scheduled delivery
Schedule::command('marketing:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('marketing:process-workflows')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('marketing:evaluate-ab-tests')->hourly()->withoutOverlapping();
Schedule::command('marketing:decay-lead-scores')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('marketing:sunset-subscribers')->dailyAt('03:30')->withoutOverlapping();

// Sales
Schedule::command('sales:process-cadences')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sales:expire-quotes')->dailyAt('01:00')->withoutOverlapping();

// Service
Schedule::command('service:check-sla')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('service:run-automations')->hourly()->withoutOverlapping();
