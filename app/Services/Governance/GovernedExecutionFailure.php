<?php

namespace App\Services\Governance;

use App\Security\ClinicalPayloads\ClinicalPayloadException;

final readonly class GovernedExecutionFailure
{
    public function __construct(public ClinicalPayloadException $exception) {}
}
