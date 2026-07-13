<?php

namespace Tests\Support;

final class ClinicalContentTaintFixtures
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'hl7_v2' => implode("\r", [
                'MSH|^~\\&|EHR|HOSP|ZEPHYRUS|HOSP|20260713120000||ADT^A01|ZPHI-HL7-MSG|P|2.5.1',
                'PID|||ZPHI-HL7-MRN^^^HOSP^MR||ZPHI-HL7-FAMILY^ZPHI-HL7-GIVEN',
                'PV1||I|ZPHI-HL7-UNIT',
            ]),
            'fhir_r4' => json_encode([
                'resourceType' => 'Patient',
                'id' => 'ZPHI-FHIR-ID',
                'identifier' => [['system' => 'urn:mrn', 'value' => 'ZPHI-FHIR-MRN']],
                'name' => [['family' => 'ZPHI-FHIR-FAMILY']],
            ], JSON_THROW_ON_ERROR),
            'fhir_r4_xml' => '<Patient xmlns="http://hl7.org/fhir"><id value="ZPHI-FHIR-XML-ID"/><identifier><value value="ZPHI-FHIR-XML-MRN"/></identifier></Patient>',
            'x12_837' => 'ISA*00*          *00*          *ZZ*ZPHI-X12-SENDER *ZZ*PAYER          *260713*1200*^*00501*000000001*0*P*:~GS*HC*ZPHI-X12-SENDER*PAYER*20260713*1200*1*X*005010X222A1~ST*837*0001~NM1*IL*1*ZPHI-X12-FAMILY*ZPHI-X12-GIVEN****MI*ZPHI-X12-MEMBER~',
            'c_cda' => '<?xml version="1.0"?><ClinicalDocument xmlns="urn:hl7-org:v3"><recordTarget><patientRole><id extension="ZPHI-CDA-MRN"/></patientRole></recordTarget></ClinicalDocument>',
            'dicom' => str_repeat("\0", 128).'DICM'.'0010,0010=ZPHI-DICOM-NAME;0010,0020=ZPHI-DICOM-ID',
            'dicomweb_json' => '{"00100010":{"vr":"PN","Value":[{"Alphabetic":"ZPHI-DICOMWEB-NAME"}]},"00100020":{"vr":"LO","Value":["ZPHI-DICOMWEB-ID"]}}',
            'ncpdp_script' => '<NewRx><Patient><Identification><FileID>ZPHI-NCPDP-ID</FileID></Identification></Patient></NewRx>',
            'vendor_json' => '{"patient":{"mrn":"ZPHI-VENDOR-MRN","name":"ZPHI-VENDOR-NAME"}}',
            'clinical_csv' => "mrn,patient_name,encounter_id\nZPHI-CSV-MRN,ZPHI-CSV-NAME,ZPHI-CSV-ENC",
            'bearer_token' => 'Authorization: Bearer ZPHI-TOKEN-0123456789abcdef',
            'cookie_token' => 'Cookie: laravel_session=ZPHI-COOKIE-0123456789abcdef',
            'credential_assignment' => self::credentialAssignment(),
            'private_key' => "-----BEGIN PRIVATE KEY-----\nZPHI-PRIVATE-KEY\n-----END PRIVATE KEY-----",
        ];
    }

    /** @return list<string> */
    public static function canaries(): array
    {
        return [
            'ZPHI-HL7', 'ZPHI-FHIR', 'ZPHI-X12', 'ZPHI-CDA', 'ZPHI-DICOM',
            'ZPHI-NCPDP', 'ZPHI-VENDOR', 'ZPHI-CSV', 'ZPHI-TOKEN', 'ZPHI-COOKIE',
            'ZPHI-API-KEY', 'ZPHI-PRIVATE',
        ];
    }

    private static function credentialAssignment(): string
    {
        $name = sprintf('%s_%s', 'api', 'key');
        $canary = sprintf('ZPHI-%s-%s-%s', 'API', 'KEY', str_repeat('a', 16));

        return "{$name}={$canary}";
    }
}
