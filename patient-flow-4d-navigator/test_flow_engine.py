#!/usr/bin/env python3
import unittest

from flow_engine import flow_event_to_fhir_bundle, parse_hl7_v2_message, reconstruct_patient_state


class FlowEngineTest(unittest.TestCase):
    def test_parse_adt_transfer(self):
        raw = "\r".join([
            "MSH|^~\\&|SUMMIT_EHR|SUMMIT_AMC|FLOW|SUMMIT_AMC|20260625010100||ADT^A02|MSG1|P|2.5.1",
            "EVN|A02|20260625010100",
            "PID|||SYN000001^^^SUMMIT_AMC^MR||FLOW^SYN000001",
            "PV1||I|MS4A^MS4A-R001^MS4A-B001^SUMMIT|||ED^ED-ADULT-001^^SUMMIT|99001^ATTENDING^SYNTHETIC|||adult_med_surg|||||||||VIS000001^^^SUMMIT_AMC^VN",
            "",
        ])
        event = parse_hl7_v2_message(raw)
        self.assertEqual(event.message_type, "ADT")
        self.assertEqual(event.trigger_event, "A02")
        self.assertEqual(event.event_type, "transfer")
        self.assertEqual(event.to_location, "MS4A-B001")
        self.assertEqual(event.from_location, "ED-ADULT-001")
        self.assertEqual(event.fhir_encounter_status, "in-progress")

    def test_reconstruct_state_and_fhir_bundle(self):
        admit_raw = "\r".join([
            "MSH|^~\\&|SUMMIT_EHR|SUMMIT_AMC|FLOW|SUMMIT_AMC|20260625010100||ADT^A01|MSG2|P|2.5.1",
            "EVN|A01|20260625010100",
            "PID|||SYN000002^^^SUMMIT_AMC^MR||FLOW^SYN000002",
            "PV1||I|MS4A^MS4A-R002^MS4A-B002^SUMMIT||||99001^ATTENDING^SYNTHETIC|||adult_med_surg|||||||||VIS000002^^^SUMMIT_AMC^VN",
            "",
        ])
        discharge_raw = admit_raw.replace("ADT^A01|MSG2", "ADT^A03|MSG3").replace("EVN|A01", "EVN|A03").replace("20260625010100", "20260625030100")
        admit = parse_hl7_v2_message(admit_raw)
        discharge = parse_hl7_v2_message(discharge_raw)
        state = reconstruct_patient_state([admit.to_dict()], "2026-06-25T02:00:00Z")
        self.assertEqual(len(state), 1)
        state = reconstruct_patient_state([admit.to_dict(), discharge.to_dict()], "2026-06-25T04:00:00Z")
        self.assertEqual(len(state), 0)
        bundle = flow_event_to_fhir_bundle(admit)
        self.assertEqual(bundle["resourceType"], "Bundle")
        self.assertEqual(bundle["entry"][0]["resource"]["resourceType"], "Encounter")


if __name__ == "__main__":
    unittest.main()
