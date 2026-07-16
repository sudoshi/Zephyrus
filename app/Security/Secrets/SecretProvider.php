<?php

namespace App\Security\Secrets;

interface SecretProvider
{
    public function scheme(): string;

    public function enabled(): bool;

    public function validateReference(SecretReferenceUri $reference): void;

    public function resolve(SecretReferenceUri $reference): ResolvedSecret;
}
