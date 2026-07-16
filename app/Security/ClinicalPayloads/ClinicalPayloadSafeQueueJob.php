<?php

namespace App\Security\ClinicalPayloads;

interface ClinicalPayloadSafeQueueJob
{
    /** @return array<string, bool|int|string|null> */
    public function clinicalPayloadSafeArguments(): array;
}
