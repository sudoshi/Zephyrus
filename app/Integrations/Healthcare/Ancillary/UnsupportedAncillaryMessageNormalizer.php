<?php

namespace App\Integrations\Healthcare\Ancillary;

use App\Integrations\Healthcare\Contracts\SourceMessageNormalizer;
use App\Integrations\Healthcare\DTO\NormalizedPayload;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;

final class UnsupportedAncillaryMessageNormalizer implements SourceMessageNormalizer
{
    public function supports(SourceMessage $message): bool
    {
        return true;
    }

    public function normalize(SourceMessage $message): NormalizedPayload
    {
        throw new AncillaryIngestException(
            'unsupported_message_family',
            'The ancillary message family is unsupported.',
            context: ['message_type' => substr($message->messageType, 0, 120)],
        );
    }
}
