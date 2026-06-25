<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\BackfillRequest;
use App\Integrations\Healthcare\DTO\ConnectorCapabilities;
use App\Integrations\Healthcare\DTO\ConnectorHealth;
use App\Integrations\Healthcare\DTO\PollRequest;
use App\Integrations\Healthcare\DTO\ReplayRequest;
use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Models\Raw\IngestRun;

interface HealthcareConnector
{
    public function sourceKey(): string;

    public function capabilities(): ConnectorCapabilities;

    public function healthCheck(): ConnectorHealth;

    public function backfill(BackfillRequest $request): IngestRun;

    public function poll(PollRequest $request): IngestRun;

    public function handleWebhook(WebhookEnvelope $webhook): IngestRun;

    public function replay(ReplayRequest $request): IngestRun;
}
