<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Services\FhirResourcePolicy;
use Tests\TestCase;

class FhirResourcePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.fhir_resources' => [
            'Encounter' => ['enabled' => true, 'scope' => 'system/Encounter.rs', 'family' => 'patient_flow'],
            'ServiceRequest' => ['enabled' => false, 'scope' => 'system/ServiceRequest.rs', 'family' => 'ancillary'],
        ]]);
    }

    public function test_allows_only_enabled_resources_with_explicit_scope(): void
    {
        $policy = new FhirResourcePolicy;

        $this->assertSame(['Encounter'], $policy->enabledResourceTypes());
        $this->assertTrue($policy->allows('Encounter'));
        $this->assertFalse($policy->allows('ServiceRequest'));
        $policy->assertCredentialAllows('Encounter', json_encode(['system/Encounter.rs']));
        $this->addToAssertionCount(1);
    }

    public function test_rejects_disabled_ancillary_resource_even_if_scope_is_present(): void
    {
        $this->expectException(IntegrationProtocolException::class);
        $this->expectExceptionMessage('fhir_resource_not_allowed');
        (new FhirResourcePolicy)->assertCredentialAllows('ServiceRequest', ['system/ServiceRequest.rs']);
    }

    public function test_rejects_enabled_resource_when_credential_scope_is_not_approved(): void
    {
        $this->expectException(IntegrationProtocolException::class);
        $this->expectExceptionMessage('fhir_scope_not_approved');
        (new FhirResourcePolicy)->assertCredentialAllows('Encounter', ['system/Location.rs']);
    }
}
