<?php

namespace App\Jobs;

/**
 * Compatibility job name for work serialized before the generic FHIR core.
 * New work is dispatched as PollFhirResource.
 */
class PollEpicFhirResource extends PollFhirResource {}
