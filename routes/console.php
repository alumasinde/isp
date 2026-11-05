<?php

use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\ScheduledTasksController;
use App\Livewire\MikrotikSync;
use App\Models\MainSiteData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

$timezone = config('app.timezone') ?: 'Asia/Dhaka';

Schedule::call(function () {
    app(ScheduledTasksController::class)->createMonthlyBill();
})->name('create-monthly-bill')->lastDayOfMonth('23:45')->timezone($timezone)->withoutOverlapping();

Schedule::call(function () {
    app(ScheduledTasksController::class)->allCustomersMonthlyBillSMS();
})->name('monthly-bill-sms')->monthlyOn(1, '10:00')->timezone($timezone)->withoutOverlapping();

Schedule::call(function () {
    app(ScheduledTasksController::class)->createAlert();
})->name('create-alert')->dailyAt('08:00')->timezone($timezone)->withoutOverlapping();

Schedule::call(function () {
    app(ScheduledTasksController::class)->userDisable();
})->name('user-disable')->dailyAt('08:30')->timezone($timezone)->withoutOverlapping();

Schedule::call(function () {
    app(MikrotikSync::class)->allSync();
})->name('mikrotik-all-sync')->daily()->timezone($timezone)->withoutOverlapping();

Schedule::call(function () {
    Artisan::call('app:poll-router-logs');
})->name('poll-router-logs')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    Artisan::call('app:expire-realtime-hotspot-users');
})->name('expire-realtime-hotspot-users')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    $days = (int) MainSiteData::getValue('log_retention_days', 30);
    app(MikrotikController::class)->pruneOldLogs($days);
})->name('prune-old-logs')->dailyAt('04:00')->timezone($timezone)->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->daily();

// Schedule::call(function () {
//     Log::info('Hello World', [
//         'time' => Carbon::now(),
//         'timezone' => config('app.timezone'),
//     ]);
// })->everyMinute();
