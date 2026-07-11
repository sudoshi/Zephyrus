<?php

namespace App\Services\Audit;

use App\Models\Audit\UserEvent;

class UserAuditPresenter
{
    /** @return array<string, mixed> */
    public function present(UserEvent $event): array
    {
        $actor = $event->actor;
        $actorIdentity = $actor !== null
            ? [
                'id' => $actor->getKey(),
                'name' => $actor->name,
                'username' => $actor->username,
                'email' => $actor->email,
                'role' => $actor->role,
            ]
            : ($event->actor_user_id !== null || $event->actor_username !== null
                ? [
                    'id' => $event->actor_user_id,
                    'name' => $event->actor_username ?? 'Former user',
                    'username' => $event->actor_username,
                    'email' => null,
                    'role' => $event->actor_role,
                ]
                : null);

        return [
            'eventUuid' => $event->event_uuid,
            'occurredAt' => $event->occurred_at?->toIso8601String(),
            'actor' => $actorIdentity,
            'actorRole' => $event->actor_role,
            'action' => $event->action,
            'category' => $event->category,
            'outcome' => $event->outcome,
            'reasonCode' => $event->reason,
            'authMethod' => $event->auth_method,
            'sourceSurface' => $event->source_surface,
            'targetType' => $event->target_type,
            'targetId' => $event->target_id,
            'routeName' => $event->route_name,
            'routeUri' => $event->uri_template,
            'httpMethod' => $event->http_method,
            'responseStatus' => $event->http_status,
            'clientIp' => $event->client_ip,
            'userAgent' => $event->user_agent,
            'changes' => $event->changes ?? [],
            'metadata' => $event->metadata ?? [],
        ];
    }
}
