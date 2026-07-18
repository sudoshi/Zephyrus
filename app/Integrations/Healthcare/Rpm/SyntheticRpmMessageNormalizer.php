<?php

namespace App\Integrations\Healthcare\Rpm;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;

class SyntheticRpmMessageNormalizer implements SourceMessageNormalizer
{
    public function supports(SourceMessage $message): bool
    {
        return str_starts_with($message->messageType, 'rpm.')
            || in_array((string) ($message->payload['event_type'] ?? ''), RpmEventVocabulary::eventTypes(), true);
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        $eventType = (string) ($message->payload['event_type'] ?? str($message->messageType)->after('rpm.')->toString());
        $externalId = $message->externalId ?? ($message->payload['external_id'] ?? $message->payload['transmission_id'] ?? null);
        $idempotencyKey = (string) ($message->payload['idempotency_key']
            ?? implode(':', array_filter([
                'rpm',
                $message->messageType,
                $externalId,
            ])));

        return new NormalizedPayload(
            messageType: $message->messageType,
            eventType: $eventType,
            payload: $message->payload,
            idempotencyKey: $idempotencyKey,
            externalId: $externalId,
            occurredAt: $message->payload['observed_at'] ?? $message->payload['occurred_at'] ?? $message->receivedAt,
            metadata: $message->metadata,
        );
    }
}
