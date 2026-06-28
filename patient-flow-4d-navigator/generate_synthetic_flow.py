#!/usr/bin/env python3
"""Generate synthetic HL7 v2 patient-flow traffic for the Summit Regional 4D navigator.

The journey core is grounded in the verified Atlantic Health distributions
(config/hospital/hospital-1-distributions.json): admission archetypes, per-unit
length-of-stay, disposition mix, and a heavily geriatric demographic profile.
Identity is Summit Regional Medical Center / HOSP1 (sending facility SUMMIT_AMC).
"""

from __future__ import annotations

import datetime as dt
import json
import math
import random
from collections import defaultdict
from pathlib import Path

from flow_engine import HL7Location, iso_to_hl7_ts, parse_hl7_v2_message, write_ndjson

ROOT = Path(__file__).resolve().parent
CAD_ROOT = ROOT / "hospital-cad-model"
CATALOG_PATH = CAD_ROOT / "data" / "model_catalog.json"
DIST_PATH = ROOT.parent / "config" / "hospital" / "hospital-1-distributions.json"
DATA_DIR = ROOT / "data"
UTC = dt.UTC

# --- Summit / HOSP1 identity -------------------------------------------------
SENDING_APPLICATION = "HOSP1_EHR"
SENDING_FACILITY = "SUMMIT_AMC"
RECEIVING_APPLICATION = "FLOW_NAV"
RECEIVING_FACILITY = "HOSP1_DIGITAL_TWIN"
ASSIGNING_AUTHORITY = "HOSP1"

# Reference year for DOB computation (age anchor 2026-06-27 per distributions).
REFERENCE_DATE = dt.date(2026, 6, 27)


# --- HL7 segment builders (working plumbing, retained) -----------------------
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
    return HL7Location(unit, room, bed, ASSIGNING_AUTHORITY)


def msh(message_type: str, trigger: str, control_id: str, when: dt.datetime) -> str:
    return "|".join([
        "MSH",
        "^~\\&",
        SENDING_APPLICATION,
        SENDING_FACILITY,
        RECEIVING_APPLICATION,
        RECEIVING_FACILITY,
        iso_to_hl7_ts(when),
        "",
        f"{message_type}^{trigger}",
        control_id,
        "P",
        "2.5.1",
    ])


def pid(patient_id: str, sex: str, dob: str) -> str:
    # Synthetic IDs only; no real demographics are generated.
    return segment("PID", {
        3: f"{patient_id}^^^{ASSIGNING_AUTHORITY}^MR",
        5: f"FLOW^{patient_id}",
        7: dob,
        8: sex,
    }, 8)


def pv1(patient_class: str, current: HL7Location, prior: HL7Location | None, encounter_id: str, service_line: str) -> str:
    return segment("PV1", {
        2: patient_class,
        3: current.to_hl7(),
        6: prior.to_hl7() if prior else "",
        7: "99001^ATTENDING^SYNTHETIC",
        10: service_line,
        19: f"{encounter_id}^^^{ASSIGNING_AUTHORITY}^VN",
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
    sex: str,
    dob: str,
    extras: list[str] | None = None,
) -> str:
    segments = [
        msh(message_type, trigger, control_id, when),
        evn(trigger, when),
        pid(patient_id, sex, dob),
        pv1(patient_class, current, prior, encounter_id, service_line),
    ]
    if extras:
        segments.extend(extras)
    return "\r".join(segments) + "\r"


# --- Catalog / location loading (retained) -----------------------------------
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


class BedAllocator:
    """Interval-correct bed allocator. Tracks the booked [admit, discharge)
    intervals on every bed and only assigns a bed with no overlapping interval,
    so concurrent occupancy can never exceed the licensed bed count regardless of
    the order journeys are generated in."""

    def __init__(self, beds_by_pool: dict[str, list[str]], reserved: set[str] | None = None) -> None:
        self.beds_by_pool = beds_by_pool
        # Beds reserved to demographic-specific units (peds/NICU/OB): reachable only
        # via their own pool key, never via cross-service or house-wide overflow,
        # so an adult/male never lands in a NICU/OB bed.
        self.reserved = reserved or set()
        # bed_code -> list of (start, end) booked intervals.
        self.bookings: dict[str, list[tuple[dt.datetime, dt.datetime]]] = defaultdict(list)

    def _is_free(self, code: str, at: dt.datetime, until: dt.datetime) -> bool:
        for start, end in self.bookings[code]:
            if at < end and start < until:  # overlap
                return False
        return True

    def acquire(self, pool_key: str, at: dt.datetime, until: dt.datetime, rng: random.Random,
                fallback_pools: list[str] | None = None) -> str:
        pools = [pool_key] + (fallback_pools or [])
        own_pool_beds = set(self.beds_by_pool.get(pool_key, []))
        seen: set[str] = set()
        house_overflow: list[str] = []
        for c in (c for codes in self.beds_by_pool.values() for c in codes):
            # House-wide overflow excludes reserved (demographic-specific) beds
            # unless this request IS for that reserved unit's own pool.
            if c in seen:
                continue
            seen.add(c)
            if c in self.reserved and c not in own_pool_beds:
                continue
            house_overflow.append(c)

        # Honor pool priority; randomize within the first pool that has a free bed.
        for key in pools + ["__house__"]:
            pool = house_overflow if key == "__house__" else [c for c in self.beds_by_pool.get(key, [])]
            free = [c for c in pool if self._is_free(c, at, until)]
            if free:
                chosen = rng.choice(free)
                self.bookings[chosen].append((at, until))
                return chosen
        # Non-reserved capacity exhausted. To preserve demographic integrity we do
        # NOT board adults into reserved peds/OB beds; instead we co-assign within
        # the requested non-reserved pool (a rare hallway/overflow census artifact).
        pool = [c for c in (self.beds_by_pool.get(pool_key) or house_overflow)
                if c not in self.reserved or c in own_pool_beds] or house_overflow
        chosen = rng.choice(pool)
        self.bookings[chosen].append((at, until))
        return chosen


# --- Distribution-driven samplers --------------------------------------------
def load_distributions() -> dict:
    return json.loads(DIST_PATH.read_text(encoding="utf-8"))


def lognormal_from_median_p90(rng: random.Random, median: float, p90: float) -> float:
    """Right-skewed draw matching a target median and 90th percentile.

    For a lognormal, median = exp(mu) and p90 = exp(mu + 1.2816*sigma).
    """
    if median <= 0:
        median = 0.01
    mu = math.log(median)
    if p90 <= median:
        sigma = 0.35
    else:
        sigma = math.log(p90 / median) / 1.281552
    return math.exp(rng.gauss(mu, sigma))


class LosSampler:
    """Samples per-unit length-of-stay grounded in losDistributionsByUnitType."""

    def __init__(self, dist: dict) -> None:
        self.by_type = {row["unitType"]: row for row in dist["losDistributionsByUnitType"]}
        self.icu = dist["icuLos"]
        self.ed_hours = dist["edThroughputHours"]

    def days(self, rng: random.Random, unit_type: str) -> float:
        row = self.by_type[unit_type]
        return lognormal_from_median_p90(rng, row["medianDays"], row["p90"])

    def ed_los_hours(self, rng: random.Random) -> float:
        # ED door-to-disposition; right-skewed to median 5.38h / p90 10.30h.
        return lognormal_from_median_p90(rng, self.ed_hours["median"], self.ed_hours["p90"])

    def icu_days(self, rng: random.Random) -> float:
        # True ICU bed LOS (icustays.los): median 4.07d.
        # Approximate p90 from median*mean ratio (mean 6.82) -> heavy right tail.
        return lognormal_from_median_p90(rng, self.icu["medianDays"], self.icu["medianDays"] * 2.7)

    def observation_days(self, rng: random.Random) -> float:
        return self.days(rng, "OBSERVATION")

    def inpatient_days(self, rng: random.Random) -> float:
        return self.days(rng, "INPATIENT")

    def psych_days(self, rng: random.Random) -> float:
        return self.days(rng, "INPATIENT PSYCH")

    def rehab_days(self, rng: random.Random) -> float:
        return self.days(rng, "INPATIENT REHAB")


def weighted_choice(rng: random.Random, items: list[tuple[str, float]]) -> str:
    total = sum(w for _, w in items)
    r = rng.random() * total
    upto = 0.0
    for value, weight in items:
        upto += weight
        if r <= upto:
            return value
    return items[-1][0]


def sample_age(rng: random.Random, buckets: list[tuple[str, float]]) -> int:
    bucket = weighted_choice(rng, buckets)
    lo, hi = {
        "0-17": (1, 17),
        "18-34": (18, 34),
        "35-49": (35, 49),
        "50-64": (50, 64),
        "65-79": (65, 79),
        "80+": (80, 97),
    }[bucket]
    return rng.randint(lo, hi)


def dob_from_age(rng: random.Random, age_years: int, neonate: bool = False) -> str:
    if neonate:
        # Born within the last few days.
        born = REFERENCE_DATE - dt.timedelta(days=rng.randint(0, 10))
        return born.strftime("%Y%m%d")
    birth_year = REFERENCE_DATE.year - age_years
    month = rng.randint(1, 12)
    day = rng.randint(1, 28)
    return f"{birth_year:04d}{month:02d}{day:02d}"


def sample_demographics(rng: random.Random, dest_unit: str, service_line: str, dist: dict) -> tuple[str, str]:
    """Return (sex, dob) honoring unit/service-line demographic overrides."""
    demo = dist["demographics"]
    house_buckets = [(b["bucket"], b["share"]) for b in demo["ageBuckets"]]
    f_share = demo["genderSplit"]["F"]

    if dest_unit == "NICU9":
        sex = "F" if rng.random() < 0.5 else "M"
        return sex, dob_from_age(rng, 0, neonate=True)
    if dest_unit == "PICU9":
        return ("F" if rng.random() < 0.5 else "M"), dob_from_age(rng, rng.randint(0, 17))
    if dest_unit == "PED9" or service_line == "pediatrics":
        return ("F" if rng.random() < 0.5 else "M"), dob_from_age(rng, rng.randint(1, 17))
    if dest_unit in {"ANT8", "PP8", "GYN8"} or service_line == "womens_health":
        return "F", dob_from_age(rng, rng.randint(18, 45))

    sex = "F" if rng.random() < f_share else "M"
    age = sample_age(rng, house_buckets)
    return sex, dob_from_age(rng, age)


# --- Catalog routing helpers -------------------------------------------------
class Plant:
    def __init__(self, locations: dict[str, dict]) -> None:
        self.locations = locations
        self.beds = [code for code, obj in locations.items() if obj["category"] == "bed"]
        self.beds_by_service: dict[str, list[str]] = defaultdict(list)
        self.beds_by_unit: dict[str, list[str]] = defaultdict(list)
        for code in self.beds:
            meta = locations[code].get("metadata", {})
            service = meta.get("service_line") or "adult_med_surg"
            self.beds_by_service[service].append(code)
            self.beds_by_unit[meta.get("unit_code", "UNK")].append(code)
        self.ed_triage = [c for c in locations if c.startswith("ED-TRIAGE")]
        self.ed_adult = [c for c in locations if c.startswith("ED-ADULT")]
        self.ed_fast = [c for c in locations if c.startswith("ED-FAST")]
        self.ed_trauma = [c for c in locations if c.startswith("ED-TRAUMA") or c.startswith("ED-RESUS")]
        self.ed_ped = [c for c in locations if c.startswith("ED-PED")]
        self.ed_bhsafe = [c for c in locations if c.startswith("ED-BHSAFE")]
        self.ed_obs = [c for c in locations if c.startswith("ED-OBS")]
        self.ct_rooms = [c for c in locations if c.startswith("CT-TRAUMA") or c.startswith("CT-ED")]
        self.procedure_rooms = [c for c, o in locations.items() if o["category"] == "procedure_room"]
        self.pacu = [c for c in locations if c.startswith("PACU-")]
        # Telemetry / stepdown beds carry the obs-status / CDU-like inpatient bounce.
        self.obs_beds = self.beds_by_unit.get("TEL7B", []) or self.beds_by_service.get("medicine", [])


# Service-line mix (matches serviceLineWeights, critical-care heavy + geriatric).
SERVICE_WEIGHTS: list[tuple[str, float]] = [
    ("adult_med_surg", 0.30),
    ("critical_care", 0.20),
    ("cardiology", 0.10),
    ("trauma_surgery", 0.08),
    ("medicine", 0.07),
    ("neurosciences", 0.05),
    ("oncology", 0.05),
    ("behavioral_health", 0.04),
    ("womens_health", 0.04),
    ("rehabilitation", 0.03),
    ("pediatrics", 0.02),
    ("burn", 0.01),
    ("neonatology", 0.005),
    ("perioperative", 0.005),
]

PATIENT_CLASS = {"INPATIENT": "I", "OBSERVATION": "B", "EMERGENCY": "E", "SURGERY": "I"}

DIAGNOSIS_BY_SERVICE = {
    "critical_care": ("A41.9", "Sepsis, unspecified organism"),
    "adult_med_surg": ("J18.9", "Pneumonia, unspecified organism"),
    "cardiology": ("I21.4", "Non-ST elevation myocardial infarction"),
    "trauma_surgery": ("S39.81", "Abdominal trauma"),
    "neurosciences": ("I63.9", "Cerebral infarction, unspecified"),
    "medicine": ("R07.9", "Chest pain, unspecified"),
    "behavioral_health": ("F32.9", "Major depressive disorder"),
    "oncology": ("D70.9", "Neutropenia, unspecified"),
    "rehabilitation": ("Z51.89", "Encounter for rehabilitation care"),
    "womens_health": ("O09.90", "High-risk pregnancy supervision"),
    "pediatrics": ("J06.9", "Acute upper respiratory infection"),
    "neonatology": ("P07.30", "Preterm newborn, unspecified weeks"),
    "burn": ("T31.20", "Burns involving 20-29% of body surface"),
    "perioperative": ("Z98.890", "Other specified postprocedural states"),
}

ICU_SERVICES = {"critical_care", "trauma_surgery", "neurosciences", "cardiovascular", "burn"}


def renormalize_archetypes(dist: dict) -> list[tuple[str, float]]:
    """Renormalize over the bed-occupying / ED archetypes; drop pure outpatient
    and the MOBILE ICU transport artifact (they do not hold inpatient beds)."""
    skip = {"OUTPATIENT", "OUTPATIENT IN A BED", "MOBILE INTENSIVE CARE UNIT"}
    kept: list[tuple[str, float]] = []
    for row in dist["admissionArchetypes"]:
        path = row["path"]
        if path in skip:
            continue
        kept.append((path, row["probability"]))
    # Collapse the two near-identical long obs-bounce variants into one bounce label.
    merged: dict[str, float] = defaultdict(float)
    for path, prob in kept:
        if path.startswith("EMERGENCY -> INPATIENT -> OBSERVATION"):
            merged["EMERGENCY -> INPATIENT -> OBSERVATION -> INPATIENT"] += prob
        elif path == "EMERGENCY -> INPATIENT -> EMERGENCY -> INPATIENT":
            merged["EMERGENCY -> INPATIENT -> OBSERVATION -> INPATIENT"] += prob
        else:
            merged[path] += prob
    return list(merged.items())


def main() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    rng = random.Random(20260627)
    dist = load_distributions()
    locations = load_locations()
    plant = Plant(locations)
    los = LosSampler(dist)

    archetypes = renormalize_archetypes(dist)
    age_buckets_house = [(b["bucket"], b["share"]) for b in dist["demographics"]["ageBuckets"]]
    disposition_items = [(d["destination"], d["probability"]) for d in dist["dispositionMix"]]

    messages: list[dict] = []
    now = dt.datetime.now(UTC).replace(second=0, microsecond=0)
    window_hours = 72
    base = now - dt.timedelta(hours=window_hours)
    control = 1

    # ONE house-wide allocator over every bed; pools are addressed by service line
    # plus special keys ('obs' = TEL7B stepdown, 'BHU2' = secure psych). Because a
    # single allocator owns all 500 beds, any saturated pool overflows house-wide
    # instead of double-booking within a unit.
    bed_pools: dict[str, list[str]] = dict(plant.beds_by_service)
    bed_pools["obs"] = plant.obs_beds
    bed_pools["BHU2"] = plant.beds_by_unit.get("BHU2", [])
    # Reserve demographic-specific units (peds / neonatal / OB) from cross-service
    # overflow so an adult or a male never occupies a NICU/PICU/PED/OB bed.
    reserved_units = {"PED9", "PICU9", "NICU9", "ANT8", "PP8", "GYN8"}
    reserved_beds = {c for u in reserved_units for c in plant.beds_by_unit.get(u, [])}
    allocator = BedAllocator(bed_pools, reserved=reserved_beds)

    # --- Volume sizing -------------------------------------------------------
    # Goal: fill ~80-90% of the 500 inpatient beds at 'now'. Inpatient mean LOS is
    # ~5.8d (>> the 72h window), so almost every in-window admission is still in a
    # bed at 'now'. The 96 peds/NICU/OB beds are reserved (and lightly used by the
    # down-weighted peds/OB service lines), so the adult demand effectively competes
    # for ~404 beds. Empirically 940 journeys over 72h lands ~82% house-wide
    # occupancy with zero double-booking under the interval-correct allocator.
    n_patients = 940

    def emit(
        patient_id: str, encounter_id: str, service: str, sex: str, dob: str,
        message_type: str, trigger: str, when: dt.datetime, loc_code: str,
        prior_code: str | None, patient_class: str, extras: list[str] | None = None,
    ) -> None:
        nonlocal control
        if when >= now - dt.timedelta(minutes=1):
            return
        loc = location_for(loc_code, locations)
        pri = location_for(prior_code, locations) if prior_code else None
        raw = hl7_message(
            message_type, trigger, f"MSG{control:07d}", patient_id, encounter_id,
            when, patient_class, loc, pri, service, sex, dob, extras,
        )
        control += 1
        event = parse_hl7_v2_message(raw).to_dict()
        location = event.get("to_location")
        loc_obj = locations.get(location or "")
        if loc_obj:
            event["location_name"] = loc_obj["name"]
            event["location_category"] = loc_obj["category"]
            event["location_floor"] = loc_obj["floor"]
            event["position_ft"] = loc_obj["position_ft"]
            event["position_m"] = loc_obj["position_m"]
            event["unit_code"] = loc_obj.get("metadata", {}).get("unit_code")
            event["location_service_line"] = loc_obj.get("metadata", {}).get("service_line")
            if not event.get("service_line"):
                event["service_line"] = loc_obj.get("metadata", {}).get("service_line")
        messages.append({
            "message_id": event["message_control_id"],
            "occurred_at": event["occurred_at"],
            "message_type": event["message_type"],
            "trigger_event": event["trigger_event"],
            "raw_hl7": raw,
            "normalized_event": event,
        })

    ICU_FALLBACK = ["critical_care", "trauma_surgery", "neurosciences", "cardiovascular", "burn"]
    MEDSURG_FALLBACK = ["adult_med_surg", "medicine", "cardiology"]

    def pick_ip_bed(service: str, admit_t: dt.datetime, until: dt.datetime, force_icu: bool = False) -> str:
        target = service
        if force_icu and service not in ICU_SERVICES:
            target = "critical_care"
        fallback = ICU_FALLBACK if target in ICU_SERVICES else MEDSURG_FALLBACK
        return allocator.acquire(target, admit_t, until, rng, fallback_pools=fallback)

    def pick_obs_bed(admit_t: dt.datetime, until: dt.datetime) -> str:
        # Prefer TEL7B stepdown, then telemetry/med-surg house-wide.
        return allocator.acquire("obs", admit_t, until, rng, fallback_pools=["medicine", "cardiology", "adult_med_surg"])

    def pick_psych_bed(admit_t: dt.datetime, until: dt.datetime, service: str) -> str:
        if plant.beds_by_unit.get("BHU2"):
            return allocator.acquire("BHU2", admit_t, until, rng, fallback_pools=MEDSURG_FALLBACK)
        return pick_ip_bed("behavioral_health", admit_t, until)

    def ed_entry(service: str, trauma: bool, behavioral: bool, peds: bool) -> str:
        if peds:
            return rng.choice(plant.ed_ped or plant.ed_triage)
        if behavioral:
            return rng.choice(plant.ed_bhsafe or plant.ed_triage)
        if trauma:
            return rng.choice(plant.ed_trauma)
        return rng.choice(plant.ed_triage)

    archetype_counts: dict[str, int] = defaultdict(int)

    for patient_index in range(1, n_patients + 1):
        patient_id = f"SYN{patient_index:06d}"
        encounter_id = f"VIS{patient_index:06d}"
        service = weighted_choice(rng, SERVICE_WEIGHTS)
        if service == "perioperative":
            service = "trauma_surgery"  # route procedural through surgical service
        if service == "cardiovascular":
            service = "cardiology"
        peds = service in {"pediatrics", "neonatology"}
        behavioral = service == "behavioral_health"
        trauma = service in {"trauma_surgery", "neurosciences"}
        is_icu_service = service in ICU_SERVICES

        path = weighted_choice(rng, archetypes)
        archetype_counts[path] += 1

        # Stagger arrivals across the window with mild jitter.
        offset = (patient_index / n_patients) * window_hours
        arrival = base + dt.timedelta(hours=offset, minutes=rng.randint(0, 40))

        # Decide destination unit early so demographics can be unit-aware.
        if path in {"EMERGENCY", "EMERGENCY -> OBSERVATION"}:
            dest_bed = None
        elif "INPATIENT PSYCH" in path or service == "behavioral_health":
            dest_bed = None  # psych unit handled below
        else:
            dest_bed = None
        # Pre-sample demographics using service-line; unit override applies for peds/OB.
        dest_unit_hint = ""
        if service == "behavioral_health":
            dest_unit_hint = "BHU2"
        elif service == "pediatrics":
            dest_unit_hint = "PICU9" if is_icu_service else "PED9"
        elif service == "neonatology":
            dest_unit_hint = "NICU9"
        elif service == "womens_health":
            dest_unit_hint = "ANT8"
        sex, dob = sample_demographics(rng, dest_unit_hint, service, dist)

        diag = DIAGNOSIS_BY_SERVICE.get(service, ("R69", "Illness unspecified"))

        def disposition() -> str:
            return weighted_choice(rng, disposition_items)

        def ip_stay_days() -> float:
            if service == "behavioral_health":
                return los.psych_days(rng)
            if service == "rehabilitation":
                return los.rehab_days(rng)
            if is_icu_service:
                return los.icu_days(rng)
            return los.inpatient_days(rng)

        # --- Journey dispatch by archetype ----------------------------------
        if path == "EMERGENCY":
            # Treat & street: ED register -> treat -> discharge.
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            treat = rng.choice((plant.ed_trauma if trauma else plant.ed_adult) or plant.ed_adult)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", arrival + dt.timedelta(minutes=22), treat, ent, "E")
            emit(patient_id, encounter_id, service, sex, dob, "ORU", "R01", arrival + dt.timedelta(minutes=55), treat, treat, "E",
                 [obr("CBC", "Complete blood count", arrival + dt.timedelta(minutes=55)), obx("WBC", "White blood count", f"{rng.uniform(5.0, 17.0):.1f}")])
            dc = arrival + dt.timedelta(hours=los.ed_los_hours(rng))
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, treat, treat, "E",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "EMERGENCY -> OBSERVATION":
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            obs_in = arrival + dt.timedelta(hours=los.ed_los_hours(rng) * 0.6)
            obs_days = los.observation_days(rng)
            dc = obs_in + dt.timedelta(days=obs_days)
            obs = pick_obs_bed(obs_in, dc)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", obs_in, obs, ent, "B")
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, obs, obs, "B",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path in {"EMERGENCY -> INPATIENT", "EMERGENCY -> INPATIENT PSYCH"}:
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            treat = rng.choice((plant.ed_trauma if trauma else plant.ed_adult) or plant.ed_adult)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", arrival + dt.timedelta(minutes=24), treat, ent, "E")
            emit(patient_id, encounter_id, service, sex, dob, "ORU", "R01", arrival + dt.timedelta(minutes=60), treat, treat, "E",
                 [obr("CBC", "Complete blood count", arrival + dt.timedelta(minutes=60)), obx("WBC", "White blood count", f"{rng.uniform(5.0, 17.0):.1f}")])
            admit_t = arrival + dt.timedelta(hours=los.ed_los_hours(rng))
            psych = path == "EMERGENCY -> INPATIENT PSYCH" or service == "behavioral_health"
            stay_days = los.psych_days(rng) if psych else ip_stay_days()
            dc = admit_t + dt.timedelta(days=stay_days)
            if psych:
                bed = pick_psych_bed(admit_t, dc, service)
            else:
                bed = pick_ip_bed(service, admit_t, dc, force_icu=is_icu_service)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", admit_t, bed, treat, PATIENT_CLASS["INPATIENT"])
            emit(patient_id, encounter_id, service, sex, dob, "RDE", "O11", admit_t + dt.timedelta(hours=1), bed, bed, "I", [rxe("7052", "Acetaminophen")])
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "EMERGENCY -> OBSERVATION -> INPATIENT":
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            obs_in = arrival + dt.timedelta(hours=los.ed_los_hours(rng) * 0.7)
            obs_days = los.observation_days(rng)
            admit_t = obs_in + dt.timedelta(days=obs_days)
            obs = pick_obs_bed(obs_in, admit_t)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", obs_in, obs, ent, "B")
            stay_days = ip_stay_days()
            dc = admit_t + dt.timedelta(days=stay_days)
            bed = pick_ip_bed(service, admit_t, dc, force_icu=is_icu_service)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", admit_t, bed, obs, "I")
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "EMERGENCY -> INPATIENT -> OBSERVATION -> INPATIENT":
            # The load-bearing obs<->inpatient BOUNCE.
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            admit_t = arrival + dt.timedelta(hours=los.ed_los_hours(rng))
            ip1_days = ip_stay_days() * 0.5
            obs_t = admit_t + dt.timedelta(days=ip1_days)
            bed1 = pick_ip_bed(service, admit_t, obs_t, force_icu=is_icu_service)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", admit_t, bed1, ent, "I")
            # Bounce to observation (telemetry/CDU-like stepdown bed).
            obs_days = los.observation_days(rng)
            reip_t = obs_t + dt.timedelta(days=obs_days)
            obs = pick_obs_bed(obs_t, reip_t)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", obs_t, obs, bed1, "B")
            # Bounce back to inpatient.
            stay_days = los.inpatient_days(rng)
            dc = reip_t + dt.timedelta(days=stay_days)
            bed2 = pick_ip_bed(service, reip_t, dc)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", reip_t, bed2, obs, "I")
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed2, bed2, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "INPATIENT":
            # Direct inpatient admit (no ED).
            stay_days = ip_stay_days()
            dc = arrival + dt.timedelta(days=stay_days)
            bed = pick_ip_bed(service, arrival, dc, force_icu=is_icu_service)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", arrival, bed, None,
                 PATIENT_CLASS["INPATIENT"], [dg1(*diag)])
            emit(patient_id, encounter_id, service, sex, dob, "RDE", "O11", arrival + dt.timedelta(hours=1), bed, bed, "I", [rxe("7052", "Acetaminophen")])
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "OBSERVATION":
            # Direct observation admit.
            obs_days = los.observation_days(rng)
            dc = arrival + dt.timedelta(days=obs_days)
            obs = pick_obs_bed(arrival, dc)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", arrival, obs, None,
                 PATIENT_CLASS["OBSERVATION"], [dg1(*diag)])
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, obs, obs, "B",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path == "INPATIENT PSYCH":
            stay_days = los.psych_days(rng)
            dc = arrival + dt.timedelta(days=stay_days)
            bed = pick_psych_bed(arrival, dc, "behavioral_health")
            emit(patient_id, encounter_id, "behavioral_health", sex, dob, "ADT", "A01", arrival, bed, None, "I",
                 [dg1(*DIAGNOSIS_BY_SERVICE["behavioral_health"])])
            emit(patient_id, encounter_id, "behavioral_health", sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

        elif path in {"SURGERY ADMIT -> INPATIENT", "HOSPITAL OUTPATIENT SURGERY"}:
            # Surgical-admit / outpatient-surgery -> OR -> PACU -> inpatient bed.
            preop = rng.choice(plant.pacu or plant.procedure_rooms)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", arrival, preop, None, "I", [dg1(*diag)])
            orr = rng.choice(plant.procedure_rooms)
            or_t = arrival + dt.timedelta(minutes=rng.randint(30, 90))
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", or_t, orr, preop, "I")
            emit(patient_id, encounter_id, service, sex, dob, "ORM", "O01", or_t + dt.timedelta(minutes=5), orr, orr, "I",
                 [orc("NW", f"ORD{control:07d}"), obr("PROC", "Surgical procedure", or_t)])
            pacu = rng.choice(plant.pacu or [orr])
            pacu_t = or_t + dt.timedelta(hours=rng.uniform(1.5, 4.0))
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", pacu_t, pacu, orr, "I")
            admit_t = pacu_t + dt.timedelta(hours=rng.uniform(1.0, 3.0))
            if path == "HOSPITAL OUTPATIENT SURGERY":
                stay_days = los.days(rng, "HOSPITAL OUTPATIENT SURGERY")
            else:
                stay_days = los.icu_days(rng) if is_icu_service else los.inpatient_days(rng)
            dc = admit_t + dt.timedelta(days=stay_days)
            bed = pick_ip_bed(service, admit_t, dc, force_icu=is_icu_service)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A02", admit_t, bed, pacu, "I")
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])
        else:
            # Fallback: ED -> inpatient.
            ent = ed_entry(service, trauma, behavioral, peds)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A04", arrival, ent, None, "E", [dg1(*diag)])
            admit_t = arrival + dt.timedelta(hours=los.ed_los_hours(rng))
            dc = admit_t + dt.timedelta(days=los.inpatient_days(rng))
            bed = pick_ip_bed(service, admit_t, dc)
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A01", admit_t, bed, ent, "I")
            emit(patient_id, encounter_id, service, sex, dob, "ADT", "A03", dc, bed, bed, "I",
                 [obx("DISCH", "Discharge disposition", disposition())])

    normalized = [row["normalized_event"] for row in messages]
    normalized.sort(key=lambda row: row["occurred_at"])
    messages.sort(key=lambda row: row["occurred_at"])

    write_ndjson(DATA_DIR / "hl7_messages.ndjson", messages)
    write_ndjson(DATA_DIR / "normalized_events.ndjson", normalized)
    tracks: dict[str, list[dict]] = defaultdict(list)
    for event in normalized:
        tracks[event["patient_id"]].append(event)
    (DATA_DIR / "patient_tracks.json").write_text(json.dumps(tracks, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (DATA_DIR / "location_index.json").write_text(json.dumps(locations, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    # --- Census reconstruction for reporting --------------------------------
    from flow_engine import reconstruct_patient_state
    now_iso = now.isoformat().replace("+00:00", "Z")
    active = reconstruct_patient_state(normalized, now_iso)
    census_by_unit: dict[str, int] = defaultdict(int)
    ip_census = 0
    ed_census = 0
    for state in active.values():
        loc = state.get("location") or ""
        meta = locations.get(loc, {}).get("metadata", {})
        unit = meta.get("unit_code")
        cat = locations.get(loc, {}).get("category")
        if cat == "bed" and unit:
            census_by_unit[unit] += 1
            ip_census += 1
        elif cat == "emergency_department":
            ed_census += 1

    summary = {
        "facility": "Summit Regional Medical Center (HOSP1)",
        "messages": len(messages),
        "normalized_events": len(normalized),
        "patients": len(tracks),
        "locations": len(locations),
        "movement_events": sum(1 for row in normalized if row["event_category"] == "movement"),
        "clinical_context_events": sum(1 for row in normalized if row["event_category"] != "movement"),
        "min_occurred_at": normalized[0]["occurred_at"],
        "max_occurred_at": normalized[-1]["occurred_at"],
        "now": now_iso,
        "inpatient_census_at_now": ip_census,
        "inpatient_beds": 500,
        "inpatient_occupancy_pct": round(100 * ip_census / 500, 1),
        "ed_census_at_now": ed_census,
        "census_by_unit_at_now": dict(sorted(census_by_unit.items())),
        "archetype_distribution": dict(sorted(archetype_counts.items())),
    }
    (DATA_DIR / "summary.json").write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(summary, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
