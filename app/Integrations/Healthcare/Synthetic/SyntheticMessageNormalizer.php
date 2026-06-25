<?php

namespace App\Integrations\Healthcare\Synthetic;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;

class SyntheticMessageNormalizer implements SourceMessageNormalizer
{
    public function supports(SourceMessage $message): bool
    {
        return str_starts_with($message->messageType, 'synthetic.')
            || isset($message->payload['event_type']);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $eventType = (string) ($message->payload['event_type'] ?? str($message->messageType)->after('synthetic.')->toString());
        $externalId = $message->externalId ?? ($message->payload['external_id'] ?? null);
        $idempotencyKey = (string) ($message->payload['idempotency_key']
            ?? implode(':', array_filter([
                'synthetic',
                $message->messageType,
                $externalId,
            ])));

        return new NormalizedPayload(
            messageType: $message->messageType,
            eventType: $eventType,
            payload: $message->payload,
            idempotencyKey: $idempotencyKey,
            externalId: $externalId,
            occurredAt: $message->payload['occurred_at'] ?? $message->receivedAt,
            metadata: $message->metadata,
        );
    }
}
