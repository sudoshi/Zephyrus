<?php

namespace Tests\Unit\Integrations;

use App\Services\PatientFlow\Hl7V2Message;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class Hl7V2MessageTest extends TestCase
{
    private const MESSAGE = "\x0bMSH|^~\\&|EHR|HOSP|ZEPHYRUS|HOSP|20260711123045.1234-0400||ORU^R01|MSG-1|P|2.5.1\rPID|1||A123^^^HOSP^MR~B456^^^STATE^PI||Doe\\F\\Jane\rOBR|1|PLACER-1|FILLER-1|TROP^Troponin^L|||20260711114500-0400\rOBX|1|ST|CODE1^First result^L||alpha\\S\\beta|mg/dL\rNTE|1||first group\rOBR|2|PLACER-2|FILLER-2|BMP^Basic panel^L|||20260711120000-0400\rOBX|1|ST|CODE2^Second result^L||one\\R\\two|mmol/L\x1c\r";

    public function test_parses_delimiters_repetitions_components_escapes_and_mllp_envelope(): void
    {
        $message = Hl7V2Message::parse(self::MESSAGE);

        $this->assertTrue($message->isValid());
        $this->assertSame('ORU', $message->messageType());
        $this->assertSame('R01', $message->triggerEvent());
        $this->assertSame('A123', $message->field('PID', 3, 1));
        $this->assertSame('B456', $message->fieldAt('PID', 1, 3, 1, 2));
        $this->assertCount(2, $message->repetitions('PID', 3));
        $this->assertSame('Doe|Jane', $message->field('PID', 5));
        $this->assertSame('alpha^beta', $message->fieldAt('OBX', 1, 5));
        $this->assertSame('one~two', $message->fieldAt('OBX', 2, 5));
        $this->assertSame('', $message->fieldAt('OBX', 99, 5));
    }

    public function test_preserves_multiple_obr_obx_groups(): void
    {
        $groups = Hl7V2Message::parse(self::MESSAGE)->groups('OBR');

        $this->assertCount(2, $groups);
        $this->assertSame(['OBR', 'OBX', 'NTE'], array_column($groups[0], 0));
        $this->assertSame(['OBR', 'OBX'], array_column($groups[1], 0));
        $this->assertSame('PLACER-2', $groups[1][0][2]);
    }

    public function test_parses_hl7_timestamp_precision_and_timezone_offset(): void
    {
        $message = Hl7V2Message::parse(self::MESSAGE);
        $timestamp = $message->timestamp('MSH', 7);

        $this->assertNotNull($timestamp);
        $this->assertSame('2026-07-11T12:30:45-04:00', $timestamp->format('c'));
        $this->assertSame('123400', $timestamp->format('u'));
        $this->assertSame('2026-07-11T11:45:00-04:00', $message->timestamp('OBR', 7, 1)?->format('c'));
        $this->assertSame('2026-07-11T12:00:00-04:00', $message->timestamp('OBR', 7, 2)?->format('c'));
    }

    public function test_validation_reports_structure_without_echoing_raw_payload(): void
    {
        $message = Hl7V2Message::parse("PID|1||SECRET-MRN\rBAD SEGMENT|value");

        $this->assertFalse($message->isValid());
        $this->assertContains('missing_or_invalid_msh', $message->validationErrors());

        try {
            $message->assertValid();
            $this->fail('Expected invalid structure exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString('SECRET-MRN', $exception->getMessage());
        }
    }
}
