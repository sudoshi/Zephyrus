<?php

namespace App\Services\Alerting;

/**
 * A PHI-free, secret-free operational alert used by the shared delivery
 * abstraction (App\Services\Alerting\OperationalAlertDispatcher).
 *
 * This is the vendor-neutral envelope that integration SLO breaches and
 * critical system-health observations route through. It intentionally carries
 * ONLY stable codes, counts, and source/component labels — never clinical
 * content, upstream responses, secrets, or free-text operator input. The
 * dispatcher runs every field through ClinicalContentGuard as defense in depth
 * before any channel sees it.
 */
final class OperationalAlert
{
    /**
     * @param  'crit'|'warn'  $severity  the paging tier (matches the cockpit vocabulary)
     * @param  non-empty-string  $domain  stable domain label ('integration' | 'system_health')
     * @param  non-empty-string  $code  a stable, PHI-free machine code (e.g. 'source_slo_breach')
     * @param  non-empty-string  $title  a stable, PHI-free human summary
     * @param  ?string  $sourceLabel  a source/component label (already PHI-free by construction)
     * @param  ?string  $deepLink  an in-app path only (never a token or URL with secrets)
     * @param  array<string,int|string|bool>  $facts  bounded stable counts/codes for the channel body
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $domain,
        public readonly string $code,
        public readonly string $title,
        public readonly ?string $sourceLabel = null,
        public readonly ?string $deepLink = null,
        public readonly array $facts = [],
    ) {}

    /** @return array<string, mixed> the exact map guarded and handed to channels */
    public function toGuardedPayload(): array
    {
        return [
            'severity' => $this->severity,
            'domain' => $this->domain,
            'code' => $this->code,
            'title' => $this->title,
            'sourceLabel' => $this->sourceLabel,
            'deepLink' => $this->deepLink,
            'facts' => $this->facts,
        ];
    }

    public function isCritical(): bool
    {
        return $this->severity === 'crit';
    }
}
