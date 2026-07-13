<?php

namespace App\Services\Auth\Oidc;

use App\Models\Auth\UserExternalIdentity;
use App\Models\Governance\IdentityLinkEvent;
use App\Models\User;
use Illuminate\Support\Str;

final class ExternalIdentityEventRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(
        UserExternalIdentity $identity,
        string $eventType,
        ?User $actor,
        string $reason,
        array $metadata = [],
    ): IdentityLinkEvent {
        return IdentityLinkEvent::query()->create([
            'identity_link_event_uuid' => (string) Str::uuid7(),
            'external_identity_id' => $identity->getKey(),
            'subject_user_id' => $identity->user_id,
            'event_type' => $eventType,
            'provider' => $identity->provider,
            'provider_subject_sha256' => hash('sha256', $identity->provider.':'.$identity->provider_subject),
            'provider_email_sha256' => filled($identity->provider_email_at_link)
                ? hash('sha256', strtolower((string) $identity->provider_email_at_link))
                : null,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
