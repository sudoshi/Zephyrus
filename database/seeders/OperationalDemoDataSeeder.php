<?php

namespace Database\Seeders;

use App\Services\Demo\OperationalDemoDataService;
use Illuminate\Database\Seeder;

class OperationalDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        app(OperationalDemoDataService::class)->rollForward();
    }
}
