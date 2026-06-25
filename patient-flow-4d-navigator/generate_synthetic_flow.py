#!/usr/bin/env python3
"""Generate synthetic HL7 v2 patient-flow traffic for the 4D navigator."""

from __future__ import annotations

import datetime as dt
import json
import random
from collections import defaultdict
from pathlib import Path

from flow_engine import HL7Location, iso_to_hl7_ts, parse_hl7_v2_message, write_ndjson

ROOT = Path(__file__).resolve().parent
CAD_ROOT = ROOT.parent / "hospital-cad-model"
CATALOG_PATH = CAD_ROOT / "data" / "model_catalog.json"
DATA_DIR = ROOT / "data"
UTC = dt.UTC


def segment(name: str, fields: dict[int, str], max_field: int) -> str:
    values = [""] * (max_field + 1)
    for index, value in fields.items():
        values[index] = value
    return name + "|" + "|".join(values[1:])


def location_for(code: str, locations: dict[str, dict]) -> HL7Location:
    obj = locations.get(code, {})
    unit = obj.get("metadata", {}).get("unit_code") or obj.get("metadata", {}).get("ed_group") or obj.get("category", "LOC").upper()
    room = code if obj.get("category") != "bed" else code.replace("-B", "-R")
    bed = code if obj.get("category") == "bed" else ""
    return HL7Location(unit, room, bed, "PARTHENON")


def msh(message_type: str, trigger: str, control_id: str, when: dt.datetime) -> str:
    return "|".join([
        "MSH",
        "^~\\&",
        "PARTHENON_EHR",
        "PARTHENON_AMC",
        "FLOW_NAV",
        "PARTHENON_DIGITAL_TWIN",
        iso_to_hl7_ts(when),
        "",
        f"{message_type}^{trigger}",
        control_id,
        "P",
        "2.5.1",
    ])


def pid(patient_id: str) -> str:
    # Synthetic IDs only; no real demographics are generated.
    return segment("PID", {
        3: f"{patient_id}^^^PARTHENON^MR",
        5: f"FLOW^{patient_id}",
        7: "19800101",
        8: "U",
    }, 8)


def pv1(patient_class: str, current: HL7Location, prior: HL7Location | None, encounter_id: str, service_line: str) -> str:
    return segment("PV1", {
        2: patient_class,
        3: current.to_hl7(),
        6: prior.to_hl7() if prior else "",
        7: "99001^ATTENDING^SYNTHETIC",
        10: service_line,
        19: f"{encounter_id}^^^PARTHENON^VN",
    }, 19)


def evn(trigger: str, when: dt.datetime) -> str:
    return segment("EVN", {1: trigger, 2: iso_to_hl7_ts(when)}, 2)


def dg1(code: str, text: str) -> str:
    return segment("DG1", {1: "1", 2: "I10", 3: f"{code}^{text}^I10"}, 3)


def obr(code: str, text: str, when: dt.datetime) -> str:
    return segment("OBR", {1: "1", 4: f"{code}^{text}^L", 7: iso_to_hl7_ts(when)}, 7)


def obx(code: str, text: str, value: str) -> str:
    return segment("OBX", {1: "1", 2: "ST", 3: f"{code}^{text}^L", 5: value, 11: "F"}, 11)


def orc(order_control: str, placer: str) -> str:
    return segment("ORC", {1: order_control, 2: placer, 3: placer}, 3)


def rxe(code: str, text: str) -> str:
    return segment("RXE", {2: f"{code}^{text}^RXNORM"}, 2)


def hl7_message(
    message_type: str,
    trigger: str,
    control_id: str,
    patient_id: str,
    encounter_id: str,
    when: dt.datetime,
    patient_class: str,
    current: HL7Location,
    prior: HL7Location | None,
    service_line: str,
    extras: list[str] | None = None,
) -> str:
    segments = [
        msh(message_type, trigger, control_id, when),
        evn(trigger, when),
        pid(patient_id),
        pv1(patient_class, current, prior, encounter_id, service_line),
    ]
    if extras:
        segments.extend(extras)
    return "\r".join(segments) + "\r"


def load_locations() -> dict[str, dict]:
    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    wanted = {"bed", "emergency_department", "procedure_room", "imaging", "procedure_support", "support_infrastructure"}
    locations = {
        obj["code"]: obj
        for obj in catalog["objects"]
        if obj["category"] in wanted
    }
    for code, obj in locations.items():
        obj["location_code"] = code
        obj["position_m"] = {
            "x": obj["position_ft"]["x"] * 0.3048,
            "y": obj["position_ft"]["level"] * 0.3048 + 1.1,
            "z": obj["position_ft"]["z"] * 0.3048,
        }
    return locations


def choose_bed(beds_by_service: dict[str, list[str]], service: str, rng: random.Random, used_beds: set[str]) -> str:
    preferred = beds_by_service.get(service) or []
    if not preferred and service in {"trauma_surgery", "critical_care", "neurosciences", "cardiovascular", "burn"}:
        preferred = beds_by_service.get("critical_care", []) + beds_by_service.get("trauma_surgery", [])
    if not preferred:
        preferred = [code for codes in beds_by_service.values() for code in codes]
    available = [code for code in preferred if code not in used_beds]
    if not available:
        available = [code for codes in beds_by_service.values() for code in codes if code not in used_beds]
    if not available:
        available = preferred
    chosen = rng.choice(available)
    used_beds.add(chosen)
    return chosen


def enrich(event: dict, locations: dict[str, dict]) -> dict:
    location = event.get("to_location")
    loc = locations.get(location or "")
    if loc:
        event["location_name"] = loc["name"]
        event["location_category"] = loc["category"]
        event["location_floor"] = loc["floor"]
        event["position_ft"] = loc["position_ft"]
        event["position_m"] = loc["position_m"]
        event["unit_code"] = loc.get("metadata", {}).get("unit_code")
        event["location_service_line"] = loc.get("metadata", {}).get("service_line")
        if not event.get("service_line"):
            event["service_line"] = loc.get("metadata", {}).get("service_line")
    return event


def add_message(messages: list[dict], raw: str, locations: dict[str, dict]) -> None:
    event = parse_hl7_v2_message(raw).to_dict()
    event = enrich(event, locations)
    messages.append({
        "message_id": event["message_control_id"],
        "occurred_at": event["occurred_at"],
        "message_type": event["message_type"],
        "trigger_event": event["trigger_event"],
        "raw_hl7": raw,
        "normalized_event": event,
    })


def generate() -> tuple[list[dict], list[dict], dict[str, dict]]:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    rng = random.Random(20260625)
    locations = load_locations()
    beds = [code for code, obj in locations.items() if obj["category"] == "bed"]
    beds_by_service: dict[str, list[str]] = defaultdict(list)
    for code in beds:
        service = locations[code].get("metadata", {}).get("service_line") or "adult_med_surg"
        beds_by_service[service].append(code)

    ed_triage = [c for c, o in locations.items() if c.startswith("ED-TRIAGE")]
    ed_adult = [c for c in locations if c.startswith("ED-ADULT")]
    ed_trauma = [c for c in locations if c.startswith("ED-TRAUMA") or c.startswith("ED-RESUS")]
    ed_obs = [c for c in locations if c.startswith("ED-OBS")]
    ct_rooms = [c for c in locations if c.startswith("CT-TRAUMA") or c.startswith("CT-ED")]
    procedure_rooms = [c for c, o in locations.items() if o["category"] == "procedure_room"]

    services = [
        ("adult_med_surg", "I", 0.20),
        ("cardiology", "I", 0.11),
        ("medicine", "I", 0.11),
        ("critical_care", "I", 0.08),
        ("trauma_surgery", "I", 0.10),
        ("neurosciences", "I", 0.07),
        ("cardiovascular", "I", 0.06),
        ("womens_health", "I", 0.06),
        ("pediatrics", "I", 0.06),
        ("oncology", "I", 0.06),
        ("behavioral_health", "I", 0.05),
        ("rehabilitation", "I", 0.04),
    ]
    weighted = []
    for service, patient_class, weight in services:
        weighted.extend([(service, patient_class)] * int(weight * 100))

    messages: list[dict] = []
    used_beds: set[str] = set()
    now = dt.datetime.now(UTC).replace(second=0, microsecond=0)
    base = now - dt.timedelta(hours=30)
    control = 1

    for patient_index in range(1, 91):
        patient_id = f"SYN{patient_index:06d}"
        encounter_id = f"VIS{patient_index:06d}"
        service, patient_class = rng.choice(weighted)
        trauma = service in {"trauma_surgery", "neurosciences"} or rng.random() < 0.12
        admit = trauma or service not in {"adult_med_surg"} or rng.random() < 0.72
        needs_procedure = trauma or service in {"cardiovascular", "neurosciences", "womens_health"} or rng.random() < 0.28
        arrival = base + dt.timedelta(minutes=patient_index * 17 + rng.randint(0, 22))
        current_code = rng.choice(ed_trauma if trauma else ed_triage)
        current = location_for(current_code, locations)
        prior = None

        def emit(message_type: str, trigger: str, when: dt.datetime, loc_code: str, prior_code: str | None, extras: list[str] | None = None) -> None:
            nonlocal control
            if when > now - dt.timedelta(minutes=1):
                return
            loc = location_for(loc_code, locations)
            pri = location_for(prior_code, locations) if prior_code else None
            raw = hl7_message(
                message_type,
                trigger,
                f"MSG{control:07d}",
                patient_id,
                encounter_id,
                when,
                "E" if loc_code.startswith("ED-") else patient_class,
                loc,
                pri,
                service,
                extras,
            )
            control += 1
            add_message(messages, raw, locations)

        emit("ADT", "A04", arrival, current_code, None, [dg1("R69", "Illness unspecified")])
        emit("ADT", "A08", arrival + dt.timedelta(minutes=8), current_code, current_code)
        treatment_code = rng.choice(ed_trauma if trauma else ed_adult)
        emit("ADT", "A02", arrival + dt.timedelta(minutes=24), treatment_code, current_code)
        current_code = treatment_code
        emit("ORM", "O01", arrival + dt.timedelta(minutes=36), current_code, current_code, [orc("NW", f"ORD{control:07d}"), obr("CBC", "Complete blood count", arrival + dt.timedelta(minutes=36))])
        emit("ORU", "R01", arrival + dt.timedelta(minutes=68), current_code, current_code, [obr("CBC", "Complete blood count", arrival + dt.timedelta(minutes=68)), obx("WBC", "White blood count", f"{rng.uniform(5.0, 17.0):.1f}")])

        if trauma or rng.random() < 0.36:
            ct_code = rng.choice(ct_rooms)
            emit("ADT", "A02", arrival + dt.timedelta(minutes=82), ct_code, current_code)
            emit("ORU", "R01", arrival + dt.timedelta(minutes=108), ct_code, ct_code, [obr("CTHEAD", "CT head without contrast", arrival + dt.timedelta(minutes=108)), obx("RADIMP", "Radiology impression", "No critical finding" if not trauma else "Trauma protocol active")])
            emit("ADT", "A02", arrival + dt.timedelta(minutes=126), current_code, ct_code)

        if admit:
            if needs_procedure:
                proc = rng.choice(procedure_rooms)
                emit("ADT", "A02", arrival + dt.timedelta(minutes=150), proc, current_code)
                emit("ORM", "O01", arrival + dt.timedelta(minutes=156), proc, proc, [orc("NW", f"ORD{control:07d}"), obr("PROC", "Procedure order", arrival + dt.timedelta(minutes=156))])
                current_code = proc
                target_service = "critical_care" if trauma or service in {"cardiovascular", "neurosciences"} else service
                bed_code = choose_bed(beds_by_service, target_service, rng, used_beds)
                emit("ADT", "A01", arrival + dt.timedelta(minutes=250), bed_code, current_code)
                current_code = bed_code
            else:
                bed_code = choose_bed(beds_by_service, service, rng, used_beds)
                emit("ADT", "A01", arrival + dt.timedelta(minutes=170), bed_code, current_code)
                current_code = bed_code

            emit("RDE", "O11", arrival + dt.timedelta(minutes=220), current_code, current_code, [rxe("7052", "Acetaminophen")])
            if locations[current_code]["metadata"].get("acuity") == "icu" and rng.random() < 0.55:
                stepdown_service = "cardiology" if service == "cardiovascular" else "adult_med_surg"
                stepdown = choose_bed(beds_by_service, stepdown_service, rng, used_beds)
                transfer_time = arrival + dt.timedelta(hours=rng.randint(8, 18), minutes=rng.randint(0, 45))
                if transfer_time < now - dt.timedelta(minutes=15):
                    emit("ADT", "A02", transfer_time, stepdown, current_code)
                    current_code = stepdown
            discharge_time = arrival + dt.timedelta(hours=rng.randint(13, 36), minutes=rng.randint(0, 50))
            if discharge_time < now - dt.timedelta(minutes=25) and rng.random() < 0.58:
                emit("ADT", "A03", discharge_time, current_code, current_code)
        else:
            obs_code = rng.choice(ed_obs)
            emit("ADT", "A02", arrival + dt.timedelta(minutes=130), obs_code, current_code)
            emit("ADT", "A03", arrival + dt.timedelta(minutes=rng.randint(220, 420)), obs_code, obs_code)

    normalized = [row["normalized_event"] for row in messages]
    normalized.sort(key=lambda row: row["occurred_at"])
    messages.sort(key=lambda row: row["occurred_at"])
    return messages, normalized, locations


def main() -> None:
    messages, normalized, locations = generate()
    write_ndjson(DATA_DIR / "hl7_messages.ndjson", messages)
    write_ndjson(DATA_DIR / "normalized_events.ndjson", normalized)
    tracks: dict[str, list[dict]] = defaultdict(list)
    for event in normalized:
        tracks[event["patient_id"]].append(event)
    (DATA_DIR / "patient_tracks.json").write_text(json.dumps(tracks, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (DATA_DIR / "location_index.json").write_text(json.dumps(locations, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    summary = {
        "messages": len(messages),
        "normalized_events": len(normalized),
        "patients": len(tracks),
        "locations": len(locations),
        "movement_events": sum(1 for row in normalized if row["event_category"] == "movement"),
        "clinical_context_events": sum(1 for row in normalized if row["event_category"] != "movement"),
        "min_occurred_at": normalized[0]["occurred_at"],
        "max_occurred_at": normalized[-1]["occurred_at"],
    }
    (DATA_DIR / "summary.json").write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
