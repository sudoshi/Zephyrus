<?php

namespace Tests\Unit\PatientFlow;

use App\Services\PatientFlow\FlowEventNormalizer;
use Tests\TestCase;

class FlowEventNormalizerTest extends TestCase
{
    public function test_parse_adt_transfer_message(): void
    {
        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260625010100||ADT^A02|MSG1|P|2.5.1',
            'EVN|A02|20260625010100',
            'PID|||SYN000001^^^AMC^MR||FLOW^SYN000001',
            'PV1||I|MS4A^MS4A-R001^MS4A-B001^PARTHENON|||ED^ED-ADULT-001^^PARTHENON|99001^ATTENDING^SYNTHETIC|||adult_med_surg|||||||||VIS000001^^^AMC^VN',
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);

        $this->assertSame('movement', $event['event_category']);
        $this->assertSame('transfer', $event['event_type']);
        $this->assertSame('ADT', $event['message_type']);
        $this->assertSame('A02', $event['trigger_event']);
        $this->assertSame('MS4A-B001', $event['to_location']);
        $this->assertSame('ED-ADULT-001', $event['from_location']);
        $this->assertSame('in-progress', $event['fhir_encounter_status']);
        $this->assertSame('inpatient', $event['fhir_encounter_class']);
    }

    public function test_parse_observation_message_as_clinical_context(): void
    {
        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260625020100||ORU^R01|MSG2|P|2.5.1',
            'EVN|R01|20260625020100',
            'PID|||SYN000001^^^AMC^MR||FLOW^SYN000001',
            'PV1||I|MS4A^MS4A-R001^MS4A-B001^PARTHENON||||99001^ATTENDING^SYNTHETIC|||adult_med_surg|||||||||VIS000001^^^AMC^VN',
            'OBR|1|||CBC^Complete blood count^L|||20260625020100',
            'OBX|1|ST|WBC^White blood count^L||8.2||||||F',
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);

        $this->assertSame('observation', $event['event_category']);
        $this->assertSame('observation', $event['event_type']);
        $this->assertSame(['CBC'], $event['order_codes']);
        $this->assertSame(['WBC'], $event['observation_codes']);
    }
}
