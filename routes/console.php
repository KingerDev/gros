<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Každý deň vytvor transakcie za splatné predplatné a splátky úverov.
Schedule::command('gros:process-payments')->dailyAt('06:00');

// V nedeľu večer pošli do mobilnej appky push so zhrnutím výdavkov za týždeň.
Schedule::command('gros:weekly-summary')->weeklyOn(0, '19:00')->timezone('Europe/Bratislava');
