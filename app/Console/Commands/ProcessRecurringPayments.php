<?php

namespace App\Console\Commands;

use App\Services\RecurringPaymentService;
use Illuminate\Console\Command;

class ProcessRecurringPayments extends Command
{
    protected $signature = 'gros:process-payments';

    protected $description = 'Vytvorí transakcie za splatné predplatné a splátky úverov a posunie ďalší dátum platby.';

    public function handle(RecurringPaymentService $service): int
    {
        $count = $service->process();

        $this->info("Vytvorených transakcií: $count");

        return self::SUCCESS;
    }
}
