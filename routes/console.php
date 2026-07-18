<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Každý deň vytvor transakcie za splatné predplatné a splátky úverov.
Schedule::command('gros:process-payments')->dailyAt('06:00');
