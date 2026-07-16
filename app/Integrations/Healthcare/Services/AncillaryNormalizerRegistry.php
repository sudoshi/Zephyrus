<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use InvalidArgumentException;

final class AncillaryNormalizerRegistry
{
    /** @var list<SourceMessageNormalizer> */
    private array $normalizers;

    /** @param iterable<SourceMessageNormalizer> $normalizers */
    public function __construct(iterable $normalizers)
    {
        $this->normalizers = is_array($normalizers) ? array_values($normalizers) : iterator_to_array($normalizers, false);
        if ($this->normalizers === []) {
            throw new InvalidArgumentException('Ancillary normalizer registry requires at least one handler.');
        }
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($message)) {
                return $normalizer->normalize($message);
            }
        }

        throw new InvalidArgumentException('Ancillary normalizer registry has no terminal unsupported handler.');
    }
}
