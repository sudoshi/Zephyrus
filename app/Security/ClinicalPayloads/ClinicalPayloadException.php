<?php

namespace App\Security\ClinicalPayloads;

use RuntimeException;

final class ClinicalPayloadException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
