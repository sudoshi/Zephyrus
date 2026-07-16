<?php

namespace App\Services\Identity;

use App\Authorization\Capability;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Carbon\CarbonInterface;

/**
 * Server-side PII redaction for identity surfaces. Raw email addresses and
 * client IPs are visible only to identity administrators (manageIdentity);
 * audit-only or view-only reviewers receive a masked email and no IP. Client
 * IPs additionally age out of every presentation after the configured
 * retention window, regardless of viewer capability. This runs in the
 * controller/presenter layer - UI filters are never the boundary.
 */
class IdentityRedactionService
{
    public function __construct(private readonly RoleCapabilityService $authorization) {}

    public function canViewSensitiveIdentity(?User $viewer): bool
    {
        return $viewer !== null
            && $this->authorization->allows($viewer, Capability::ManageIdentity);
    }

    public function maskEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return mb_substr($email, 0, 1).'***';
        }

        return mb_substr($email, 0, 1).'***@'.substr($email, $at + 1);
    }

    public function presentEmail(?string $email, bool $reveal): ?string
    {
        return $reveal ? $email : $this->maskEmail($email);
    }

    public function ipRetentionDays(): int
    {
        return max(0, (int) config('audit.ip_retention_days', 90));
    }

    public function presentClientIp(?string $clientIp, ?CarbonInterface $occurredAt, bool $reveal): ?string
    {
        if (! $reveal || $clientIp === null) {
            return null;
        }

        if ($occurredAt !== null && $occurredAt->lessThan(now()->subDays($this->ipRetentionDays()))) {
            return null;
        }

        return $clientIp;
    }
}
