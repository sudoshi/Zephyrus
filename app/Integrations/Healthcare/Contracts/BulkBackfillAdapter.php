<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\BackfillRequest;
use App\Integrations\Healthcare\DTO\BulkBackfillResult;

interface BulkBackfillAdapter
{
    public function backfill(string $sourceKey, BackfillRequest $request): BulkBackfillResult;
}
