#!/usr/bin/env python3
"""HL7/FHIR patient-flow normalization primitives for the 3D/4D navigator.

The code intentionally uses only Python's standard library. Production adapters
should sit behind an interface engine or managed integration platform, validate
against local HL7 conformance profiles, and enforce PHI controls before events
reach visualization clients.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import json
import re
from dataclasses import asdict, dataclass, field
from typing import Iterable

UTC = dt.UTC

ADT_MOVEMENT_TYPES = {
    "A01": "admit",
    "A02": "transfer",
    "A03": "discharge",
    "A04": "register",
    "A05": "preadmit",
    "A06": "outpatient_to_inpatient",
    "A07": "inpatient_to_outpatient",
    "A08": "update",
    "A09": "departing_tracking",
    "A10": "arriving_tracking",
    "A11": "cancel_admit",
    "A12": "cancel_transfer",
    "A13": "cancel_discharge",
    "A40": "merge_patient",
}

MESSAGE_CATEGORIES = {
    "ADT": "movement",
    "ORM": "order",
    "OML": "order",
    "ORU": "observation",
    "RDE": "medication",
    "RAS": "medication",
    "SIU": "schedule",
    "DFT": "financial",
    "MDM": "document",
}

FHIR_ENCOUNTER_STATUS_BY_EVENT = {
    "admit": "in-progress",
    "register": "arrived",
    "preadmit": "planned",
    "transfer": "in-progress",
    "outpatient_to_inpatient": "in-progress",
    "inpatient_to_outpatient": "in-progress",
    "update": "in-progress",
    "departing_tracking": "in-progress",
    "arriving_tracking": "in-progress",
    "discharge": "finished",
    "cancel_admit": "cancelled",
    "cancel_transfer": "in-progress",
    "cancel_discharge": "in-progress",
}

PATIENT_CLASS_TO_FHIR_CLASS = {
    "I": "inpatient",
    "E": "emergency",
    "O": "outpatient",
    "P": "preadmission",
    "R": "recurring",
    "B": "observation",
}


def parse_hl7_ts(value: str | None) -> str:
    """Parse common HL7 TS strings into ISO-8601 UTC."""
    if not value:
        return dt.datetime.now(UTC).isoformat().replace("+00:00", "Z")
    raw = value.strip()
    match = re.match(r"^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?", raw)
    if not match:
        return dt.datetime.now(UTC).isoformat().replace("+00:00", "Z")
    parts = [int(p) if p else None for p in match.groups()]
    year = parts[0]
    month = parts[1] or 1
    day = parts[2] or 1
    hour = parts[3] or 0
    minute = parts[4] or 0
    second = parts[5] or 0
    return dt.datetime(year, month, day, hour, minute, second, tzinfo=UTC).isoformat().replace("+00:00", "Z")


def iso_to_dt(value: str) -> dt.datetime:
    if value.endswith("Z"):
        value = value[:-1] + "+00:00"
    parsed = dt.datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=UTC)
    return parsed.astimezone(UTC)


def iso_to_hl7_ts(value: str | dt.datetime) -> str:
    if isinstance(value, str):
        value = iso_to_dt(value)
    return value.astimezone(UTC).strftime("%Y%m%d%H%M%S")


def stable_hash(value: str, length: int = 16) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:length]


@dataclass
class HL7Location:
    point_of_care: str = ""
    room: str = ""
    bed: str = ""
    facility: str = ""

    @property
    def location_code(self) -> str:
        return self.bed or self.room or self.point_of_care or "UNKNOWN"

    @classmethod
    def parse(cls, value: str | None) -> "HL7Location":
        if not value:
            return cls()
        parts = value.split("^")
        while len(parts) < 4:
            parts.append("")
        return cls(parts[0], parts[1], parts[2], parts[3])

    def to_hl7(self) -> str:
        return "^".join([self.point_of_care, self.room, self.bed, self.facility]).rstrip("^")


@dataclass
class HL7Message:
    raw: str
    segments: list[list[str]]

    @classmethod
    def parse(cls, raw: str) -> "HL7Message":
        cleaned = raw.strip("\x0b\x1c\r\n ")
        lines = [line for line in re.split(r"\r\n|\n|\r", cleaned) if line]
        segments = [line.split("|") for line in lines]
        return cls(raw=raw, segments=segments)

    def first(self, segment: str) -> list[str] | None:
        for item in self.segments:
            if item and item[0] == segment:
                return item
        return None

    def all(self, segment: str) -> list[list[str]]:
        return [item for item in self.segments if item and item[0] == segment]

    def field(self, segment: str, field_number: int, component: int | None = None) -> str:
        seg = self.first(segment)
        if not seg:
            return ""
        if segment == "MSH":
            if field_number == 1:
                value = "|"
            else:
                index = field_number - 1
                value = seg[index] if index < len(seg) else ""
        else:
            value = seg[field_number] if field_number < len(seg) else ""
        if component:
            parts = value.split("^")
            return parts[component - 1] if component - 1 < len(parts) else ""
        return value

    @property
    def message_type(self) -> str:
        return self.field("MSH", 9, 1)

    @property
    def trigger_event(self) -> str:
        return self.field("MSH", 9, 2)


@dataclass
class FlowEvent:
    event_id: str
    event_category: str
    event_type: str
    message_type: str
    trigger_event: str
    patient_id: str
    patient_display_id: str
    encounter_id: str
    occurred_at: str
    recorded_at: str
    from_location: str | None = None
    to_location: str | None = None
    point_of_care: str | None = None
    room: str | None = None
    bed: str | None = None
    patient_class: str | None = None
    fhir_encounter_status: str | None = None
    fhir_encounter_class: str | None = None
    source_system: str | None = None
    message_control_id: str | None = None
    attending_provider: str | None = None
    service_line: str | None = None
    priority: str | None = None
    diagnosis_codes: list[str] = field(default_factory=list)
    order_codes: list[str] = field(default_factory=list)
    observation_codes: list[str] = field(default_factory=list)
    medication_codes: list[str] = field(default_factory=list)
    cancellation_of_event_id: str | None = None
    raw_message_hash: str | None = None
    source_protocol: str = "hl7v2"
    deidentified: bool = True
    metadata: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return asdict(self)


def parse_hl7_v2_message(raw: str, source_protocol: str = "hl7v2") -> FlowEvent:
    msg = HL7Message.parse(raw)
    message_type = msg.message_type or "UNKNOWN"
    trigger = msg.trigger_event or ""
    source_system = msg.field("MSH", 3) or "UNKNOWN"
    message_control_id = msg.field("MSH", 10) or stable_hash(raw, 10)
    occurred_at = parse_hl7_ts(msg.field("EVN", 2) or msg.field("MSH", 7))
    recorded_at = dt.datetime.now(UTC).isoformat().replace("+00:00", "Z")
    pid = msg.field("PID", 3, 1) or f"UNKNOWN-{stable_hash(raw, 8)}"
    visit = msg.field("PV1", 19, 1) or f"ENC-{stable_hash(pid + occurred_at, 10)}"
    assigned = HL7Location.parse(msg.field("PV1", 3))
    prior = HL7Location.parse(msg.field("PV1", 6))
    patient_class = msg.field("PV1", 2) or None
    category = MESSAGE_CATEGORIES.get(message_type, "clinical_context")
    event_type = ADT_MOVEMENT_TYPES.get(trigger, trigger.lower() if trigger else message_type.lower())
    if message_type != "ADT" and category != "movement":
        event_type = category

    diagnosis_codes = [seg[3].split("^")[0] for seg in msg.all("DG1") if len(seg) > 3 and seg[3]]
    order_codes: list[str] = []
    observation_codes: list[str] = []
    medication_codes: list[str] = []
    for obr in msg.all("OBR"):
        if len(obr) > 4 and obr[4]:
            order_codes.append(obr[4].split("^")[0])
    for obx in msg.all("OBX"):
        if len(obx) > 3 and obx[3]:
            observation_codes.append(obx[3].split("^")[0])
    for rxe in msg.all("RXE"):
        if len(rxe) > 2 and rxe[2]:
            medication_codes.append(rxe[2].split("^")[0])
    for orc in msg.all("ORC"):
        if len(orc) > 3 and orc[3]:
            order_codes.append(orc[3].split("^")[0])

    event_id = f"{source_system}-{message_control_id}-{stable_hash(raw, 8)}"
    cancellation = None
    if event_type.startswith("cancel_"):
        cancellation = stable_hash(f"{pid}:{visit}:{trigger}:{assigned.location_code}:{occurred_at}", 16)

    return FlowEvent(
        event_id=event_id,
        event_category=category,
        event_type=event_type,
        message_type=message_type,
        trigger_event=trigger,
        patient_id=stable_hash(pid),
        patient_display_id=f"PT-{stable_hash(pid, 6).upper()}",
        encounter_id=stable_hash(visit),
        occurred_at=occurred_at,
        recorded_at=recorded_at,
        from_location=prior.location_code if prior.location_code != "UNKNOWN" else None,
        to_location=assigned.location_code if assigned.location_code != "UNKNOWN" else None,
        point_of_care=assigned.point_of_care or None,
        room=assigned.room or None,
        bed=assigned.bed or None,
        patient_class=patient_class,
        fhir_encounter_status=FHIR_ENCOUNTER_STATUS_BY_EVENT.get(event_type, "in-progress"),
        fhir_encounter_class=PATIENT_CLASS_TO_FHIR_CLASS.get(patient_class or "", "unknown"),
        source_system=source_system,
        message_control_id=message_control_id,
        attending_provider=msg.field("PV1", 7, 1) or None,
        service_line=msg.field("PV1", 10) or None,
        priority=msg.field("PV2", 25) or None,
        diagnosis_codes=diagnosis_codes,
        order_codes=sorted(set(order_codes)),
        observation_codes=sorted(set(observation_codes)),
        medication_codes=sorted(set(medication_codes)),
        cancellation_of_event_id=cancellation,
        raw_message_hash=stable_hash(raw, 32),
        source_protocol=source_protocol,
        metadata={
            "sending_facility": msg.field("MSH", 4),
            "receiving_application": msg.field("MSH", 5),
            "hl7_version": msg.field("MSH", 12),
            "pv1_assigned_patient_location": assigned.to_hl7(),
            "pv1_prior_patient_location": prior.to_hl7(),
        },
    )


def flow_event_to_fhir_bundle(event: FlowEvent) -> dict:
    location_reference = f"Location/{event.to_location or 'unknown'}"
    encounter = {
        "resourceType": "Encounter",
        "id": event.encounter_id,
        "identifier": [{"system": "urn:hosp1:encounter", "value": event.encounter_id}],
        "status": event.fhir_encounter_status or "in-progress",
        "class": {
            "system": "http://terminology.hl7.org/CodeSystem/v3-ActCode",
            "code": event.fhir_encounter_class or "unknown",
        },
        "subject": {"reference": f"Patient/{event.patient_id}"},
        "period": {"start": event.occurred_at},
        "location": [{
            "location": {"reference": location_reference},
            "status": "completed" if event.event_type == "discharge" else "active",
            "period": {"start": event.occurred_at},
        }],
    }
    patient = {
        "resourceType": "Patient",
        "id": event.patient_id,
        "identifier": [{"system": "urn:hosp1:synthetic-patient", "value": event.patient_display_id}],
    }
    location = {
        "resourceType": "Location",
        "id": event.to_location or "unknown",
        "name": event.to_location or "Unknown location",
        "physicalType": {
            "coding": [{
                "system": "http://terminology.hl7.org/CodeSystem/location-physical-type",
                "code": "bd" if event.bed else "ro",
            }]
        },
    }
    return {
        "resourceType": "Bundle",
        "type": "message",
        "timestamp": event.recorded_at,
        "entry": [
            {"resource": encounter},
            {"resource": patient},
            {"resource": location},
        ],
    }


def reconstruct_patient_state(events: Iterable[dict], as_of: str | None = None) -> dict[str, dict]:
    cutoff = iso_to_dt(as_of) if as_of else None
    active: dict[str, dict] = {}
    for event in sorted(events, key=lambda e: e["occurred_at"]):
        if cutoff and iso_to_dt(event["occurred_at"]) > cutoff:
            continue
        patient_id = event["patient_id"]
        if event["event_type"] in {"discharge", "cancel_admit"}:
            active.pop(patient_id, None)
            continue
        if event.get("to_location") and event.get("event_category") in {"movement", "order", "observation", "medication", "schedule"}:
            active[patient_id] = {
                "patient_id": patient_id,
                "patient_display_id": event["patient_display_id"],
                "encounter_id": event["encounter_id"],
                "location": event.get("to_location"),
                "event_type": event["event_type"],
                "patient_class": event.get("patient_class"),
                "service_line": event.get("service_line"),
                "last_event_at": event["occurred_at"],
            }
    return active


def occupancy_by_location(events: Iterable[dict], as_of: str | None = None) -> dict[str, int]:
    occupancy: dict[str, int] = {}
    for state in reconstruct_patient_state(events, as_of).values():
        location = state.get("location")
        if location:
            occupancy[location] = occupancy.get(location, 0) + 1
    return occupancy


def read_ndjson(path) -> list[dict]:
    with open(path, "r", encoding="utf-8") as handle:
        return [json.loads(line) for line in handle if line.strip()]


def write_ndjson(path, rows: Iterable[dict]) -> None:
    with open(path, "w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, separators=(",", ":"), sort_keys=True) + "\n")

