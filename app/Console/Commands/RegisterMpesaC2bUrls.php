<?php

namespace App\Console\Commands;

use App\Services\MpesaService;
use Illuminate\Console\Command;

class RegisterMpesaC2bUrls extends Command
{
    protected $signature = 'mpesa:register-c2b';

    protected $description = 'Register the M-Pesa C2B validation and confirmation URLs with Safaricom';

    public function handle(MpesaService $mpesa): int
    {
        try {
            $response = $mpesa->registerC2bUrls();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('M-Pesa C2B URLs registered.');
        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
