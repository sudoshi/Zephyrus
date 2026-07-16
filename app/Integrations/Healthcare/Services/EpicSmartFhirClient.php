<?php

namespace App\Integrations\Healthcare\Services;

/**
 * Compatibility alias for the original operational slice.
 *
 * Protocol behavior now lives in SmartBackendFhirClient. Epic-specific
 * discovery/conformance remains a source profile concern rather than being
 * embedded in the FHIR transport core.
 */
class EpicSmartFhirClient extends SmartBackendFhirClient {}
