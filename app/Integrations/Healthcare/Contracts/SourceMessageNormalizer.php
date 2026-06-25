<?php

namespace App\Integrations\Healthcare\Contracts;

use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;

interface SourceMessageNormalizer
{
    public function supports(SourceMessage $message): bool;

    public function normalize(SourceMessage $message): NormalizedPayload;
}
