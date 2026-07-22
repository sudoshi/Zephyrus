<?php

namespace App\Services\Mobile;

use InvalidArgumentException;

/**
 * Server-owned T1–T4 notification urgency registry (plan §6.4).
 *
 * Native clients never invent urgency: every push resolves its interruption
 * level, importance, sound/haptic, acknowledgement, expiry, collapse, quiet-hours,
 * and escalation from a tier here. Copy is a class, never PHI text. The registry
 * is validated against the recognized {@see PersonaRelayPolicy} event taxonomy.
 */
class NotificationUrgencyRegistry
{
    public const TIER_CODES = ['T1', 'T2', 'T3', 'T4'];

    public const INTERRUPTION_LEVELS = ['critical', 'time-sensitive', 'active', 'passive'];

    public const ANDROID_IMPORTANCE = ['HIGH', 'DEFAULT', 'LOW'];

    public const COLLAPSE_STRATEGIES = ['never', 'per_entity', 'per_domain'];

    public function __construct(private readonly PersonaRelayPolicy $relayPolicy) {}

    /** @return array<string, mixed> */
    public function tiers(): array
    {
        return (array) config('hummingbird-notifications.tiers', []);
    }

    /**
     * The full tier record for a tier code.
     *
     * @return array<string, mixed>
     */
    public function tier(string $code): array
    {
        $tier = $this->tiers()[$code] ?? null;
        if (! is_array($tier)) {
            throw new InvalidArgumentException("unknown_notification_tier:{$code}");
        }

        return $tier;
    }

    public function defaultTier(): string
    {
        return (string) config('hummingbird-notifications.default_tier', 'T3');
    }

    /** Resolve the tier code for an event type (explicit mapping, else the default). */
    public function tierCodeForEvent(string $eventType): string
    {
        $map = (array) config('hummingbird-notifications.events', []);

        return (string) ($map[$eventType] ?? $this->defaultTier());
    }

    /**
     * The resolved tier record for an event type, plus its code.
     *
     * @return array<string, mixed>
     */
    public function forEvent(string $eventType): array
    {
        $code = $this->tierCodeForEvent($eventType);

        return ['tier' => $code] + $this->tier($code);
    }

    /**
     * Validate the registry's internal integrity. Returns a list of problems;
     * an empty list means the registry is well-formed.
     *
     * @return list<string>
     */
    public function validationErrors(): array
    {
        $errors = [];
        $tiers = $this->tiers();

        if (array_keys($tiers) !== self::TIER_CODES) {
            $errors[] = 'tiers must be exactly T1, T2, T3, T4 in order.';
        }

        foreach ($tiers as $code => $tier) {
            if (! is_array($tier)) {
                $errors[] = "tier {$code} must be a mapping.";

                continue;
            }
            if (! in_array($tier['ios_interruption_level'] ?? null, self::INTERRUPTION_LEVELS, true)) {
                $errors[] = "tier {$code} ios_interruption_level is invalid.";
            }
            if (! in_array($tier['android_importance'] ?? null, self::ANDROID_IMPORTANCE, true)) {
                $errors[] = "tier {$code} android_importance is invalid.";
            }
            if (! in_array($tier['collapse_strategy'] ?? null, self::COLLAPSE_STRATEGIES, true)) {
                $errors[] = "tier {$code} collapse_strategy is invalid.";
            }
            if (! is_bool($tier['requires_ack'] ?? null)) {
                $errors[] = "tier {$code} requires_ack must be a boolean.";
            } elseif ($tier['requires_ack'] === true && ! is_int($tier['ack_timeout_seconds'] ?? null)) {
                $errors[] = "tier {$code} requires_ack but has no integer ack_timeout_seconds.";
            }
            if (! is_bool($tier['quiet_hours_exempt'] ?? null)) {
                $errors[] = "tier {$code} quiet_hours_exempt must be a boolean.";
            }
            if (! is_string($tier['copy_class'] ?? null) || ! str_contains((string) ($tier['copy_class'] ?? ''), 'no_phi')) {
                $errors[] = "tier {$code} copy_class must be a PHI-free class.";
            }
            $escalation = $tier['escalation'] ?? null;
            if ($escalation !== null) {
                if (! is_array($escalation)
                    || ! is_int($escalation['after_seconds'] ?? null)
                    || ! in_array($escalation['to_tier'] ?? null, self::TIER_CODES, true)
                ) {
                    $errors[] = "tier {$code} escalation must reference {after_seconds, to_tier}.";
                }
            }
        }

        if (! in_array($this->defaultTier(), self::TIER_CODES, true)) {
            $errors[] = 'default_tier must be one of T1–T4.';
        }

        foreach ((array) config('hummingbird-notifications.events', []) as $eventType => $code) {
            if (! $this->relayPolicy->isRecognizedEventType((string) $eventType)
                && ! $this->relayPolicy->isDocumentedAsNotEmittedYet((string) $eventType)
            ) {
                $errors[] = "event {$eventType} is not a recognized relay event type.";
            }
            if (! in_array($code, self::TIER_CODES, true)) {
                $errors[] = "event {$eventType} maps to invalid tier {$code}.";
            }
        }

        return $errors;
    }
}
