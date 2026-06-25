#!/usr/bin/env python3
"""
Generate a concept-level, navigable 3D CAD/BIM bundle for the 500-bed
Tier 1 / Level I trauma academic medical center model.

Outputs:
  - model/hospital_model.glb: runtime 3D model with per-object metadata.
  - cad/hospital_model.dxf: CAD mesh exchange model with 3DFACE geometry.
  - bim/hospital_model.ifc: simplified IFC4 semantic hierarchy and asset list.
  - 3dtiles/tileset.json: single-tile OGC 3D Tiles wrapper around the GLB.
  - data/model_catalog.json: object catalog for search, inspection and handoff.
  - viewer/index.html, viewer/styles.css, viewer/app.js: Three.js navigation tool.
  - README.md: standard-format and model-use notes.

This generator uses only Python's standard library so the model can be
regenerated without external CAD/BIM packages.
"""

from __future__ import annotations

import base64
import datetime as dt
import json
import math
import os
import struct
import textwrap
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parent
OUT = ROOT
MODEL_DIR = OUT / "model"
CAD_DIR = OUT / "cad"
BIM_DIR = OUT / "bim"
TILES_DIR = OUT / "3dtiles"
DATA_DIR = OUT / "data"
VIEWER_DIR = OUT / "viewer"

FT_TO_M = 0.3048
FLOOR_HEIGHT_FT = 15.0
BASE_FLOOR = -2


MATERIALS = {
    "floor": {"rgba": [0.58, 0.59, 0.54, 1.0], "label": "Floor slabs"},
    "public": {"rgba": [0.93, 0.78, 0.36, 0.78], "label": "Public / arrival"},
    "med_surg": {"rgba": [0.42, 0.65, 0.76, 0.86], "label": "Med/Surg"},
    "telemetry": {"rgba": [0.34, 0.60, 0.49, 0.88], "label": "Telemetry"},
    "icu": {"rgba": [0.86, 0.28, 0.25, 0.92], "label": "Adult ICU"},
    "burn_icu": {"rgba": [0.78, 0.24, 0.16, 0.92], "label": "Burn ICU"},
    "behavioral": {"rgba": [0.64, 0.52, 0.74, 0.88], "label": "Behavioral health"},
    "women": {"rgba": [0.87, 0.47, 0.61, 0.88], "label": "Women and infants"},
    "pediatrics": {"rgba": [0.40, 0.67, 0.83, 0.88], "label": "Pediatrics"},
    "oncology": {"rgba": [0.46, 0.70, 0.38, 0.88], "label": "Oncology / BMT"},
    "rehab": {"rgba": [0.59, 0.71, 0.38, 0.88], "label": "Rehab"},
    "ed": {"rgba": [0.88, 0.38, 0.25, 0.88], "label": "Emergency"},
    "procedure": {"rgba": [0.32, 0.44, 0.75, 0.90], "label": "Procedural"},
    "imaging": {"rgba": [0.24, 0.58, 0.78, 0.90], "label": "Imaging"},
    "logistics": {"rgba": [0.62, 0.56, 0.48, 0.82], "label": "Logistics"},
    "research": {"rgba": [0.55, 0.49, 0.76, 0.84], "label": "Research / education"},
    "corridor_public": {"rgba": [0.95, 0.82, 0.42, 0.96], "label": "Public corridors"},
    "corridor_patient": {"rgba": [0.43, 0.70, 0.72, 0.96], "label": "Patient corridors"},
    "corridor_clean": {"rgba": [0.62, 0.76, 0.50, 0.96], "label": "Clean supply"},
    "corridor_soiled": {"rgba": [0.66, 0.44, 0.35, 0.96], "label": "Soiled / waste"},
    "corridor_sterile": {"rgba": [0.85, 0.86, 0.92, 0.96], "label": "Sterile core"},
    "corridor_restricted": {"rgba": [0.46, 0.45, 0.48, 0.96], "label": "Restricted staff"},
    "bed": {"rgba": [0.97, 0.96, 0.88, 1.0], "label": "Beds"},
    "isolation_bed": {"rgba": [0.90, 0.98, 0.97, 1.0], "label": "Isolation-capable beds"},
    "elevator_public": {"rgba": [0.22, 0.58, 0.84, 0.95], "label": "Public elevators"},
    "elevator_bed": {"rgba": [0.17, 0.46, 0.64, 0.95], "label": "Bed elevators"},
    "elevator_trauma": {"rgba": [0.94, 0.22, 0.18, 1.0], "label": "Trauma elevators"},
    "elevator_service": {"rgba": [0.43, 0.44, 0.45, 0.95], "label": "Service elevators"},
    "utility": {"rgba": [0.38, 0.40, 0.43, 0.82], "label": "Infrastructure"},
    "helipad": {"rgba": [0.16, 0.60, 0.56, 1.0], "label": "Helipad"},
}


FLOOR_PROGRAM = [
    (-2, "B2", "Central plant, bulk oxygen, fuel, emergency power, utility tunnels", False),
    (-1, "B1", "Loading dock, materials, sterile processing, pharmacy receiving, morgue, waste", False),
    (1, "L1", "ED, trauma arrival, imaging, decontamination, public entry, security", True),
    (2, "L2", "Perioperative platform, cath/EP/IR/endoscopy, PACU, behavioral health", False),
    (3, "L3", "Adult ICUs, burn ICU, respiratory therapy, family consult", False),
    (4, "L4", "Adult med/surg tower pods", True),
    (5, "L5", "Adult med/surg tower pods", True),
    (6, "L6", "Adult med/surg and specialty swing pods", True),
    (7, "L7", "Telemetry, stepdown and cardiology pods", True),
    (8, "L8", "Women's health, antepartum, postpartum, gyn surgery, LDR support", True),
    (9, "L9", "Pediatrics, PICU, NICU, child life, family amenities", True),
    (10, "L10", "Oncology, BMT, cellular therapy, infusion bridge", True),
    (11, "L11", "Acute inpatient rehab and therapy gyms", True),
    (12, "L12", "Education, simulation, conference, command overflow", True),
    (13, "L13", "Research, data science, clinical trials, biobank bridge", False),
    (14, "PH", "Mechanical penthouse, air handling, exhaust, elevator machine rooms", False),
]


UNIT_PROGRAM = [
    ("MS4A", "Adult Med/Surg A", 4, "adult_med_surg", "med_surg", 28, 10.00, "Med/surg universal rooms; swing telemetry"),
    ("MS4B", "Adult Med/Surg B", 4, "adult_med_surg", "med_surg", 28, 10.00, "Standard acute-care pod"),
    ("MS5A", "Adult Med/Surg C", 5, "adult_med_surg", "med_surg", 28, 10.00, "Respiratory isolation swing capacity"),
    ("MS5B", "Adult Med/Surg D", 5, "adult_med_surg", "med_surg", 28, 10.00, "Geriatric-friendly fall prevention"),
    ("MS6A", "Adult Med/Surg E", 6, "adult_med_surg", "med_surg", 24, 10.00, "Acuity-adaptable shell"),
    ("MS6B", "Adult Med/Surg F", 6, "adult_med_surg", "med_surg", 24, 10.00, "Future specialty conversion"),
    ("TEL7A", "Adult Telemetry A", 7, "cardiology", "telemetry", 32, 12.50, "Telemetry and stepdown headwalls"),
    ("TEL7B", "Adult Telemetry B", 7, "medicine", "telemetry", 32, 12.50, "ED decompression telemetry demand"),
    ("MICU3", "Medical ICU", 3, "critical_care", "icu", 24, 25.00, "High-observation ICU rooms"),
    ("SICU3", "Surgical/Trauma ICU", 3, "trauma_surgery", "icu", 24, 25.00, "Direct trauma OR, CT, blood bank, helipad path"),
    ("NSICU3", "Neuroscience ICU", 3, "neurosciences", "icu", 20, 20.00, "Stroke and neurotrauma adjacency"),
    ("CVICU3", "Cardiovascular ICU", 3, "cardiovascular", "icu", 20, 15.00, "Cardiac surgery, ECMO and cath adjacency"),
    ("BURN3", "Burn ICU", 3, "burn", "burn_icu", 8, 50.00, "Burn isolation and hydrotherapy support"),
    ("BHU2", "Inpatient Behavioral Health", 2, "behavioral_health", "behavioral", 24, 0.00, "Ligature-resistant secure pod"),
    ("ANT8", "Antepartum High-Risk OB", 8, "womens_health", "women", 12, 8.33, "High-risk antepartum, rapid C-section path"),
    ("PP8", "Postpartum / Mother Baby", 8, "womens_health", "women", 28, 7.14, "Couplet-care and infant security"),
    ("GYN8", "Gynecology Surgery", 8, "womens_health", "women", 8, 12.50, "Short-stay surgical recovery"),
    ("PED9", "Pediatric Acute Care", 9, "pediatrics", "pediatrics", 24, 16.67, "Family-centered pediatric rooms"),
    ("PICU9", "Pediatric ICU", 9, "pediatrics", "pediatrics", 12, 25.00, "Pediatric critical care and trauma"),
    ("NICU9", "Neonatal ICU", 9, "neonatology", "pediatrics", 12, 25.00, "Single-family NICU rooms"),
    ("ONC10", "Oncology / Hematology", 10, "oncology", "oncology", 24, 16.67, "Oncology and infusion adjacency"),
    ("BMT10", "Bone Marrow Transplant / Cellular Therapy", 10, "oncology", "oncology", 16, 25.00, "Protective environment rooms"),
    ("AIR11", "Acute Inpatient Rehabilitation", 11, "rehabilitation", "rehab", 20, 5.00, "Therapy gym adjacency"),
]


HALLWAY_PROGRAM = [
    (-2, "utility_tunnel", "emergency_response", "restricted", 6, 8.0, False, "MEP, emergency power, facilities access"),
    (-1, "service_main", "clean_supply", "clean", 8, 10.0, False, "Materials, pharmacy, sterile dispatch"),
    (-1, "soiled_waste", "waste", "soiled", 5, 8.0, False, "Soiled, waste, morgue, separated from clean supply"),
    (1, "public_arrival", "public_visitor", "public", 8, 12.0, True, "Lobby, wayfinding, registration and waiting"),
    (1, "ed_patient", "patient_transport", "patient", 12, 10.0, False, "ED treatment and imaging circulation"),
    (1, "trauma_priority", "emergency_response", "patient", 4, 12.0, False, "Ambulance/helipad to trauma bay, CT, OR elevator"),
    (2, "periop_restricted", "clinical_staff", "restricted", 10, 10.0, False, "OR restricted corridor ring"),
    (2, "sterile_core", "sterile_instrument", "sterile", 6, 8.0, False, "Sterile core to OR and procedure rooms"),
    (2, "soiled_return", "soiled_material", "soiled", 4, 8.0, False, "Soiled case cart return"),
    (3, "icu_patient", "patient_transport", "patient", 12, 10.0, False, "ICU family/staff/patient transport separation"),
    (4, "inpatient_public", "public_visitor", "public", 6, 8.0, True, "Visitor circulation outside unit control points"),
    (4, "inpatient_clinical", "clinical_staff", "patient", 8, 9.0, False, "Staff and patient-care loop"),
    (5, "inpatient_public", "public_visitor", "public", 6, 8.0, True, "Visitor circulation outside unit control points"),
    (5, "inpatient_clinical", "clinical_staff", "patient", 8, 9.0, False, "Staff and patient-care loop"),
    (6, "inpatient_public", "public_visitor", "public", 6, 8.0, True, "Visitor circulation outside unit control points"),
    (6, "inpatient_clinical", "clinical_staff", "patient", 8, 9.0, False, "Staff and patient-care loop"),
    (7, "telemetry_clinical", "patient_transport", "patient", 10, 9.0, False, "Stepdown/telemetry high transport volume"),
    (8, "women_infant_security", "security", "restricted", 8, 9.0, False, "Infant security and controlled access"),
    (9, "pediatric_family", "public_visitor", "public", 6, 8.0, True, "Pediatric family-centered circulation"),
    (9, "pediatric_clinical", "patient_transport", "patient", 8, 9.0, False, "Pediatric clinical and critical-care transport"),
    (10, "oncology_protective", "patient_transport", "restricted", 8, 9.0, False, "Oncology/BMT protective traffic control"),
    (11, "rehab_mobility", "patient_transport", "patient", 6, 10.0, True, "Mobility and therapy paths"),
    (12, "education_public", "public_visitor", "public", 5, 8.0, True, "Education and conference"),
    (13, "research_specimen", "research_specimen", "clean", 5, 8.0, False, "Research specimen and data science circulation"),
    (14, "mechanical_service", "emergency_response", "restricted", 5, 8.0, False, "Mechanical penthouse service access"),
]


ELEVATOR_PROGRAM = [
    ("PUB", "Public visitor passenger elevators", "public_passenger", 8, 4500, False, False, True),
    ("BED", "Inpatient bed/stretcher elevators", "bed_stretcher", 8, 6000, True, False, False),
    ("TRM", "Trauma/helipad priority elevators", "trauma_priority", 2, 6500, True, True, False),
    ("OIC", "OR/ICU priority elevators", "or_icu_priority", 4, 6500, True, False, False),
    ("CLN", "Clean supply/service elevators", "service_clean", 4, 6000, False, False, False),
    ("SOL", "Soiled/waste elevators", "service_soiled", 3, 6000, False, False, False),
    ("MAT", "Materials/pharmacy elevators", "materials_pharmacy", 2, 5000, False, False, False),
    ("FOD", "Food/nutrition elevators", "food_nutrition", 2, 5000, False, False, False),
    ("FIR", "Fire service/emergency operations elevators", "fire_service", 2, 6500, True, False, False),
]


ED_PROGRAM = [
    ("TRIAGE", "Walk-in triage", "emergency", 8, False),
    ("TRAUMA", "Trauma resuscitation bay", "emergency", 8, True),
    ("RESUS", "Medical resuscitation bay", "emergency", 6, True),
    ("ADULT", "Adult ED treatment room", "emergency", 48, False),
    ("PED", "Pediatric ED treatment room", "emergency", 12, False),
    ("BHSAFE", "Behavioral health safe ED room", "behavioral_health", 8, False),
    ("FAST", "Fast track / low-acuity chair", "low_acuity", 16, False),
    ("OBS", "Clinical decision / observation room", "observation", 24, False),
    ("AII", "ED airborne isolation room", "emergency", 6, False),
    ("DECON", "Decontamination shower position", "emergency", 12, False),
]


PROCEDURE_PROGRAM = [
    ("GENOR", "General inpatient OR", "operating_room", 8, "general_surgery", False),
    ("TROR", "Dedicated trauma OR", "operating_room", 2, "trauma_surgery", True),
    ("HYBOR", "Hybrid OR", "hybrid_or", 2, "vascular_cardiac_trauma", True),
    ("CARDOR", "Cardiac OR", "operating_room", 2, "cardiac_surgery", False),
    ("NEUOR", "Neurosurgery OR", "operating_room", 2, "neurosurgery", True),
    ("TXPOR", "Transplant OR", "operating_room", 2, "transplant", False),
    ("ORTHOR", "Orthopedic trauma OR", "operating_room", 2, "orthopedics", True),
    ("ROBOR", "Robotic / minimally invasive OR", "operating_room", 2, "robotic_surgery", False),
    ("BURNOR", "Burn / wound OR", "operating_room", 1, "burn", True),
    ("OBOR", "Obstetric C-section OR", "labor_delivery_or", 3, "obstetrics", False),
    ("CATH", "Cardiac catheterization lab", "cath_lab", 4, "interventional_cardiology", True),
    ("EP", "Electrophysiology lab", "ep_lab", 2, "electrophysiology", False),
    ("IR", "Interventional radiology suite", "interventional_radiology", 4, "interventional_radiology", True),
    ("ENDO", "Endoscopy procedure room", "endoscopy", 6, "gastroenterology", False),
    ("BRONCH", "Bronchoscopy procedure room", "bronchoscopy", 2, "pulmonology", False),
]


@dataclass
class Box:
    code: str
    name: str
    category: str
    material: str
    floor: int
    x: float
    y: float
    z: float
    sx: float
    sy: float
    sz: float
    metadata: dict = field(default_factory=dict)

    @property
    def bounds(self) -> tuple[float, float, float, float, float, float]:
        return (
            self.x - self.sx / 2,
            self.y - self.sy / 2,
            self.z - self.sz / 2,
            self.x + self.sx / 2,
            self.y + self.sy / 2,
            self.z + self.sz / 2,
        )


def floor_y(floor: int) -> float:
    if floor <= -1:
        return (floor - BASE_FLOOR) * FLOOR_HEIGHT_FT
    return (floor - BASE_FLOOR - 1) * FLOOR_HEIGHT_FT


def ensure_dirs() -> None:
    for directory in [MODEL_DIR, CAD_DIR, BIM_DIR, TILES_DIR, DATA_DIR, VIEWER_DIR]:
        directory.mkdir(parents=True, exist_ok=True)


def sanitize_ifc(value: str) -> str:
    return value.replace("'", "''")


def service_material(service: str, acuity: str) -> str:
    if acuity == "icu":
        return "icu"
    if acuity == "burn_icu":
        return "burn_icu"
    if "women" in service or service == "neonatology":
        return "women"
    if service in {"pediatrics", "neonatology"}:
        return "pediatrics"
    if service == "oncology":
        return "oncology"
    if service == "rehabilitation":
        return "rehab"
    if "behavioral" in service:
        return "behavioral"
    if acuity == "telemetry":
        return "telemetry"
    return "med_surg"


def add_floor_plates(boxes: list[Box]) -> None:
    for floor, floor_code, program, public_access in FLOOR_PROGRAM:
        y = floor_y(floor)
        width = 430 if floor <= 3 else 330
        depth = 270 if floor <= 3 else 210
        if floor in {12, 13}:
            width, depth = 330, 190
        if floor == 14:
            width, depth = 260, 150
        boxes.append(Box(
            code=f"FLOOR-{floor_code}",
            name=f"{floor_code} {program}",
            category="floor",
            material="floor",
            floor=floor,
            x=0,
            y=y,
            z=0,
            sx=width,
            sy=0.55,
            sz=depth,
            metadata={
                "floor_code": floor_code,
                "program": program,
                "public_access": public_access,
                "level_ft": round(y, 2),
            },
        ))


def add_core_and_elevators(boxes: list[Box]) -> None:
    base_y = floor_y(-2) + 2
    top_y = floor_y(14) + 16
    shaft_height = top_y - base_y
    y = base_y + shaft_height / 2
    positions = []
    groups = [
        ("PUB", -46, -28, "elevator_public"),
        ("BED", 22, -28, "elevator_bed"),
        ("TRM", -8, -74, "elevator_trauma"),
        ("OIC", 58, -68, "elevator_bed"),
        ("CLN", 82, 38, "elevator_service"),
        ("SOL", 112, 38, "elevator_service"),
        ("MAT", 142, 18, "elevator_service"),
        ("FOD", 142, 54, "elevator_service"),
        ("FIR", -96, 38, "elevator_service"),
    ]
    program_by_prefix = {row[0]: row for row in ELEVATOR_PROGRAM}
    for prefix, base_x, base_z, material in groups:
        row = program_by_prefix[prefix]
        count = row[3]
        cols = min(4, count)
        for idx in range(count):
            col = idx % cols
            r = idx // cols
            positions.append((prefix, idx + 1, base_x + col * 15, base_z + r * 16, material, row))

    for prefix, number, x, z, material, row in positions:
        _, bank_name, elevator_class, _, capacity_lb, stretcher, helipad, public_access = row
        code = f"{prefix}-{number:02d}"
        boxes.append(Box(
            code=code,
            name=f"{bank_name} {number:02d}",
            category="elevator",
            material=material,
            floor=99,
            x=x,
            y=y,
            z=z,
            sx=10.5,
            sy=shaft_height,
            sz=11.5,
            metadata={
                "elevator_class": elevator_class,
                "capacity_lb": capacity_lb,
                "bed_stretcher_capable": stretcher,
                "serves_helipad": helipad,
                "public_access": public_access,
                "lowest_floor": -2,
                "highest_floor": 14,
                "standard_mapping": "Vertical transport fleet separated by public, bed, trauma, OR/ICU, clean, soiled, materials, food and fire-service flows.",
            },
        ))

    boxes.append(Box(
        code="HELIPAD-ROOF-01",
        name="Rooftop trauma helipad",
        category="helipad",
        material="helipad",
        floor=14,
        x=-5,
        y=floor_y(14) + 24,
        z=-106,
        sx=82,
        sy=1.4,
        sz=82,
        metadata={
            "flow": "Direct protected trauma transfer to trauma elevators, ED resuscitation, CT, OR and SICU.",
            "criticality": "life_safety",
        },
    ))
    for code, x, z, sx, sz in [
        ("HELIPAD-H-1", -5, -106, 46, 7),
        ("HELIPAD-H-2", -23, -106, 7, 38),
        ("HELIPAD-H-3", 13, -106, 7, 38),
    ]:
        boxes.append(Box(
            code=code,
            name="Helipad H marking",
            category="helipad",
            material="public",
            floor=14,
            x=x,
            y=floor_y(14) + 25.2,
            z=z,
            sx=sx,
            sy=0.7,
            sz=sz,
            metadata={"graphic": "helipad H"},
        ))


def add_hallways(boxes: list[Box]) -> None:
    floor_codes = {floor: code for floor, code, _, _ in FLOOR_PROGRAM}
    material_by_contamination = {
        "public": "corridor_public",
        "patient": "corridor_patient",
        "clean": "corridor_clean",
        "soiled": "corridor_soiled",
        "sterile": "corridor_sterile",
        "restricted": "corridor_restricted",
    }
    for floor, group_code, flow_class, contamination, count, min_width, public, notes in HALLWAY_PROGRAM:
        y = floor_y(floor) + 3.0
        mat = material_by_contamination.get(contamination, "corridor_patient")
        for idx in range(count):
            axis_x = idx % 2 == 0
            ring = idx // 2
            length = 96 - min(ring * 7, 28)
            width = min_width
            if axis_x:
                x = -150 + (idx % 6) * 60
                z = -88 + ring * 34
                sx, sz = length, width
            else:
                x = -162 + ring * 52
                z = -78 + (idx % 7) * 28
                sx, sz = width, length
            if floor <= 2:
                x *= 1.18
                z *= 1.12
            code = f"{floor_codes[floor]}-{group_code}-{idx + 1:02d}"
            boxes.append(Box(
                code=code,
                name=f"{floor_codes[floor]} {group_code.replace('_', ' ')} segment {idx + 1:02d}",
                category="corridor",
                material=mat,
                floor=floor,
                x=x,
                y=y,
                z=z,
                sx=sx,
                sy=1.2,
                sz=sz,
                metadata={
                    "flow_class": flow_class,
                    "contamination_class": contamination,
                    "min_clear_width_ft": min_width,
                    "public_access": public,
                    "optimization_notes": notes,
                    "clean_soiled_separation": contamination in {"clean", "sterile", "soiled"},
                },
            ))


def unit_offsets_for_floor(floor: int, units: list[tuple]) -> list[tuple[float, float]]:
    n = len(units)
    if floor <= 3:
        base = [(-135, -58), (20, -58), (-135, 58), (20, 58), (155, 0)]
    elif n == 1:
        base = [(0, 0)]
    elif n == 2:
        base = [(-82, 0), (82, 0)]
    elif n == 3:
        base = [(-110, -35), (65, -35), (-22, 58)]
    else:
        base = [(-112, -45), (70, -45), (-112, 52), (70, 52), (0, 0)]
    return base[:n]


def add_inpatient_units(boxes: list[Box]) -> None:
    units_by_floor: dict[int, list[tuple]] = {}
    for unit in UNIT_PROGRAM:
        units_by_floor.setdefault(unit[2], []).append(unit)

    for floor, units in units_by_floor.items():
        offsets = unit_offsets_for_floor(floor, units)
        for unit, (origin_x, origin_z) in zip(units, offsets):
            unit_code, unit_name, _, service_line, acuity, beds, isolation_pct, notes = unit
            material = service_material(service_line, acuity)
            unit_w = 135 if floor <= 3 else 128
            unit_d = 84 if beds <= 20 else 104
            y = floor_y(floor)
            boxes.append(Box(
                code=f"UNIT-{unit_code}",
                name=unit_name,
                category="care_unit",
                material=material,
                floor=floor,
                x=origin_x,
                y=y + 1.25,
                z=origin_z,
                sx=unit_w,
                sy=2.5,
                sz=unit_d,
                metadata={
                    "unit_code": unit_code,
                    "service_line": service_line,
                    "acuity": acuity,
                    "planned_beds": beds,
                    "isolation_target_pct": isolation_pct,
                    "optimization_notes": notes,
                },
            ))
            isolation_count = int(round(beds * isolation_pct / 100.0)) if isolation_pct > 0 else 0
            room_w = 14.0 if acuity not in {"icu", "burn_icu"} else 18.0
            room_d = 19.0 if acuity not in {"icu", "burn_icu"} else 22.0
            columns = max(6, math.ceil(beds / 4))
            for i in range(beds):
                row = i // columns
                col = i % columns
                side = 1 if row % 2 == 0 else -1
                lane = row // 2
                x = origin_x - unit_w / 2 + 12 + col * (room_w + 2.4)
                z = origin_z + side * (unit_d / 2 - room_d / 2 - 5 - lane * (room_d + 5))
                if x > origin_x + unit_w / 2 - 12:
                    wrap = x - (origin_x + unit_w / 2 - 12)
                    x = origin_x - unit_w / 2 + 12 + wrap
                    z -= side * (room_d + 7)
                bed_num = i + 1
                room_code = f"{unit_code}-R{bed_num:03d}"
                bed_code = f"{unit_code}-B{bed_num:03d}"
                isolation = bed_num <= isolation_count
                room_material = material
                bed_material = "isolation_bed" if isolation else "bed"
                boxes.append(Box(
                    code=room_code,
                    name=f"{unit_name} patient room {bed_num:03d}",
                    category="patient_room",
                    material=room_material,
                    floor=floor,
                    x=x,
                    y=y + 2.1,
                    z=z,
                    sx=room_w,
                    sy=4.2,
                    sz=room_d,
                    metadata={
                        "unit_code": unit_code,
                        "bed_code": bed_code,
                        "service_line": service_line,
                        "acuity": acuity,
                        "single_patient_room": True,
                        "same_handed": True,
                        "acuity_adaptable": True,
                        "negative_or_protective_isolation_candidate": isolation,
                        "hand_hygiene_on_entry": True,
                        "ceiling_lift": acuity in {"icu", "burn_icu", "picu", "rehab"},
                    },
                ))
                boxes.append(Box(
                    code=bed_code,
                    name=f"{unit_name} bed {bed_num:03d}",
                    category="bed",
                    material=bed_material,
                    floor=floor,
                    x=x - room_w * 0.16,
                    y=y + 4.85,
                    z=z - room_d * 0.08,
                    sx=6.8,
                    sy=2.2,
                    sz=3.2,
                    metadata={
                        "unit_code": unit_code,
                        "service_line": service_line,
                        "acuity": acuity,
                        "licensed_bed": True,
                        "staffed_bed": True,
                        "icu_capable": acuity in {"icu", "burn_icu", "picu", "nicu"},
                        "telemetry_capable": acuity in {"telemetry", "icu", "burn_icu", "picu", "nicu"},
                        "negative_pressure_capable": isolation,
                        "protective_environment_capable": service_line == "oncology" and isolation,
                        "medical_gas_headwall": True,
                        "nurse_call_endpoint": f"NC-{bed_code}",
                    },
                ))


def add_ed_and_imaging(boxes: list[Box]) -> None:
    y = floor_y(1)
    group_origins = {
        "TRIAGE": (-160, -85),
        "TRAUMA": (-52, -94),
        "RESUS": (36, -94),
        "ADULT": (-130, 10),
        "PED": (72, 30),
        "BHSAFE": (162, 60),
        "FAST": (-180, 72),
        "OBS": (10, 74),
        "AII": (158, -28),
        "DECON": (-212, -62),
    }
    for group_code, group_name, acuity, count, trauma in ED_PROGRAM:
        ox, oz = group_origins[group_code]
        cols = max(3, min(8, math.ceil(math.sqrt(count * 1.7))))
        for i in range(count):
            row, col = divmod(i, cols)
            code = f"ED-{group_code}-{i + 1:03d}"
            sx = 13.0 if group_code not in {"TRAUMA", "RESUS", "DECON"} else 18.0
            sz = 16.0 if group_code not in {"TRAUMA", "RESUS", "DECON"} else 22.0
            boxes.append(Box(
                code=code,
                name=f"{group_name} {i + 1:03d}",
                category="emergency_department",
                material="ed" if group_code != "BHSAFE" else "behavioral",
                floor=1,
                x=ox + col * (sx + 3),
                y=y + 2.3,
                z=oz + row * (sz + 4),
                sx=sx,
                sy=4.6,
                sz=sz,
                metadata={
                    "ed_group": group_code,
                    "acuity": acuity,
                    "trauma_priority": trauma,
                    "direct_observation": True,
                    "nurse_call": group_code not in {"DECON"},
                    "behavioral_safe": group_code == "BHSAFE",
                    "negative_pressure_capable": group_code == "AII",
                    "decontamination": group_code == "DECON",
                },
            ))
    imaging = [
        ("CT-TRAUMA-01", "Trauma CT 1", -16, -34, True),
        ("CT-TRAUMA-02", "Trauma CT 2", 18, -34, True),
        ("CT-ED-03", "ED CT 3", 56, -32, False),
        ("XR-ED-01", "ED X-ray 1", 94, -34, False),
        ("US-ED-01", "ED ultrasound", 130, -34, False),
        ("MRI-ED-01", "Emergency MRI", 166, -34, False),
    ]
    for code, name, x, z, trauma in imaging:
        boxes.append(Box(
            code=code,
            name=name,
            category="imaging",
            material="imaging",
            floor=1,
            x=x,
            y=y + 2.5,
            z=z,
            sx=24,
            sy=5,
            sz=22,
            metadata={
                "modality": code.split("-")[0],
                "trauma_priority": trauma,
                "stroke_priority": code.startswith("CT"),
                "shielding_required": True,
                "downtime_route": "Portable imaging and direct radiology escalation",
            },
        ))


def add_procedural_platform(boxes: list[Box]) -> None:
    y = floor_y(2)
    origins = [
        (-170, -78), (-126, -78), (-82, -78), (-38, -78), (6, -78), (50, -78), (94, -78), (138, -78),
        (-170, -30), (-126, -30), (-82, -30), (-38, -30), (6, -30), (50, -30), (94, -30), (138, -30),
        (-170, 28), (-126, 28), (-82, 28), (-38, 28), (6, 28), (50, 28), (94, 28), (138, 28),
        (-170, 78), (-126, 78), (-82, 78), (-38, 78), (6, 78), (50, 78), (94, 78), (138, 78),
        (182, -78), (182, -30), (182, 28), (182, 78), (-214, -78), (-214, -30), (-214, 28), (-214, 78),
        (0, 122), (44, 122), (88, 122), (132, 122),
    ]
    idx = 0
    for group_code, group_name, platform, count, specialty, trauma in PROCEDURE_PROGRAM:
        for j in range(count):
            x, z = origins[idx % len(origins)]
            idx += 1
            sx = 32 if "OR" in group_code or platform in {"operating_room", "hybrid_or"} else 28
            sz = 30 if platform in {"operating_room", "hybrid_or"} else 24
            code = f"{group_code}-{j + 1:02d}"
            boxes.append(Box(
                code=code,
                name=f"{group_name} {j + 1:02d}",
                category="procedure_room",
                material="procedure",
                floor=2,
                x=x,
                y=y + 2.7,
                z=z,
                sx=sx,
                sy=5.4,
                sz=sz,
                metadata={
                    "platform_type": platform,
                    "specialty_focus": specialty,
                    "trauma_priority": trauma,
                    "restricted_zone": True,
                    "sterile_core_adjacent": True,
                    "anesthesia_required": True,
                    "hybrid_imaging_capable": platform in {"hybrid_or", "cath_lab", "interventional_radiology"},
                    "emergency_power_required": True,
                },
            ))
    support_rooms = [
        ("PACU-01", "PACU Phase I", -70, 124, 92, 28, "procedure"),
        ("PACU-02", "PACU Phase II", 54, 124, 86, 28, "procedure"),
        ("SPD-STERILE-CORE", "Sterile core and case cart dispatch", 0, 0, 108, 26, "corridor_sterile"),
        ("BLOOD-RELEASE", "Emergency blood release refrigerator", -8, -116, 22, 14, "icu"),
    ]
    for code, name, x, z, sx, sz, mat in support_rooms:
        boxes.append(Box(
            code=code,
            name=name,
            category="procedure_support",
            material=mat,
            floor=2,
            x=x,
            y=y + 2.2,
            z=z,
            sx=sx,
            sy=4.4,
            sz=sz,
            metadata={"restricted_zone": True, "supports_trauma": "BLOOD" in code or "STERILE" in code},
        ))


def add_logistics_research_infra(boxes: list[Box]) -> None:
    logistics = [
        (-1, "LOAD-DOCK", "Loading dock and receiving", -170, -88, 96, 44, "logistics", "clean_supply"),
        (-1, "SPD-DECON", "Sterile processing decontamination", -70, -86, 76, 36, "corridor_soiled", "soiled_material"),
        (-1, "SPD-PREP", "Sterile processing prep/pack", 26, -86, 82, 36, "corridor_sterile", "sterile_instrument"),
        (-1, "PHARM-CLEAN", "Central pharmacy clean room suite", 122, -86, 72, 36, "logistics", "pharmacy"),
        (-1, "MORGUE", "Morgue and decedent care", 174, 50, 52, 34, "corridor_soiled", "deceased"),
        (-1, "WASTE-HOLD", "Waste and regulated medical waste holding", -154, 54, 70, 36, "corridor_soiled", "waste"),
        (-2, "CUP-GEN", "N+1 generator plant", -120, -62, 112, 44, "utility", "emergency_power"),
        (-2, "CUP-MEDGAS", "Bulk oxygen and medical gas farm", 50, -62, 92, 44, "utility", "medical_gas"),
        (-2, "CUP-WATER", "Emergency water and pumping", 142, 42, 72, 38, "utility", "water"),
        (12, "SIM-CENTER", "Simulation and procedure training center", -70, -32, 110, 58, "research", "education"),
        (12, "COMMAND-ALT", "Alternate incident command / conference", 82, -32, 92, 48, "research", "emergency_management"),
        (13, "CLINICAL-TRIALS", "Clinical trials and data science", -72, -32, 112, 58, "research", "research"),
        (13, "BIOBANK", "Biobank and specimen processing", 78, -32, 92, 48, "research", "research_specimen"),
        (14, "MECH-AHU", "Mechanical penthouse air handling", 0, -14, 180, 74, "utility", "hvac"),
    ]
    for floor, code, name, x, z, sx, sz, mat, flow in logistics:
        boxes.append(Box(
            code=code,
            name=name,
            category="support_infrastructure",
            material=mat,
            floor=floor,
            x=x,
            y=floor_y(floor) + 2.5,
            z=z,
            sx=sx,
            sy=5.0,
            sz=sz,
            metadata={
                "flow_class": flow,
                "criticality": "mission_critical" if floor <= -1 or "COMMAND" in code else "business_critical",
                "emergency_powered": floor <= -1 or floor == 14,
            },
        ))


def generate_boxes() -> list[Box]:
    boxes: list[Box] = []
    add_floor_plates(boxes)
    add_core_and_elevators(boxes)
    add_hallways(boxes)
    add_inpatient_units(boxes)
    add_ed_and_imaging(boxes)
    add_procedural_platform(boxes)
    add_logistics_research_infra(boxes)
    return boxes


def cube_geometry_binary() -> tuple[bytes, dict[str, int]]:
    # Unit cube centered at origin; Three/glTF uses Y-up. Coordinates are meters.
    positions = [
        # +X
        0.5, -0.5, -0.5, 0.5, 0.5, -0.5, 0.5, 0.5, 0.5, 0.5, -0.5, 0.5,
        # -X
        -0.5, -0.5, 0.5, -0.5, 0.5, 0.5, -0.5, 0.5, -0.5, -0.5, -0.5, -0.5,
        # +Y
        -0.5, 0.5, -0.5, -0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5, -0.5,
        # -Y
        -0.5, -0.5, 0.5, -0.5, -0.5, -0.5, 0.5, -0.5, -0.5, 0.5, -0.5, 0.5,
        # +Z
        -0.5, -0.5, 0.5, 0.5, -0.5, 0.5, 0.5, 0.5, 0.5, -0.5, 0.5, 0.5,
        # -Z
        0.5, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5, 0.5, -0.5, 0.5, 0.5, -0.5,
    ]
    normals = [
        *([1, 0, 0] * 4),
        *([-1, 0, 0] * 4),
        *([0, 1, 0] * 4),
        *([0, -1, 0] * 4),
        *([0, 0, 1] * 4),
        *([0, 0, -1] * 4),
    ]
    indices = [
        0, 1, 2, 0, 2, 3,
        4, 5, 6, 4, 6, 7,
        8, 9, 10, 8, 10, 11,
        12, 13, 14, 12, 14, 15,
        16, 17, 18, 16, 18, 19,
        20, 21, 22, 20, 22, 23,
    ]
    binary = b""
    pos_offset = len(binary)
    binary += struct.pack("<" + "f" * len(positions), *positions)
    norm_offset = len(binary)
    binary += struct.pack("<" + "f" * len(normals), *normals)
    idx_offset = len(binary)
    binary += struct.pack("<" + "H" * len(indices), *indices)
    return binary, {
        "pos_offset": pos_offset,
        "pos_len": len(positions) * 4,
        "norm_offset": norm_offset,
        "norm_len": len(normals) * 4,
        "idx_offset": idx_offset,
        "idx_len": len(indices) * 2,
    }


def write_glb(boxes: list[Box]) -> None:
    binary, offsets = cube_geometry_binary()
    materials = []
    material_to_index = {}
    for key, spec in MATERIALS.items():
        rgba = spec["rgba"]
        material_to_index[key] = len(materials)
        materials.append({
            "name": key,
            "pbrMetallicRoughness": {
                "baseColorFactor": rgba,
                "metallicFactor": 0.0,
                "roughnessFactor": 0.72,
            },
            "alphaMode": "BLEND" if rgba[3] < 1 else "OPAQUE",
            "doubleSided": True,
            "extras": {"label": spec["label"]},
        })

    meshes = []
    mesh_by_material = {}
    for key in MATERIALS:
        mesh_by_material[key] = len(meshes)
        meshes.append({
            "name": f"cube_{key}",
            "primitives": [{
                "attributes": {"POSITION": 0, "NORMAL": 1},
                "indices": 2,
                "material": material_to_index[key],
            }],
        })

    nodes = []
    root_children = []
    for box in boxes:
        translation = [box.x * FT_TO_M, box.y * FT_TO_M, box.z * FT_TO_M]
        scale = [box.sx * FT_TO_M, box.sy * FT_TO_M, box.sz * FT_TO_M]
        node = {
            "name": box.code,
            "mesh": mesh_by_material[box.material],
            "translation": translation,
            "scale": scale,
            "extras": {
                "code": box.code,
                "name": box.name,
                "category": box.category,
                "floor": box.floor,
                "material": box.material,
                **box.metadata,
            },
        }
        root_children.append(len(nodes))
        nodes.append(node)

    gltf = {
        "asset": {
            "version": "2.0",
            "generator": "Parthenon hospital CAD generator",
            "copyright": "Concept model. Not for construction.",
        },
        "scene": 0,
        "scenes": [{"name": "500-bed Level I Trauma Academic Medical Center", "nodes": root_children}],
        "nodes": nodes,
        "meshes": meshes,
        "materials": materials,
        "buffers": [{"byteLength": len(binary)}],
        "bufferViews": [
            {"buffer": 0, "byteOffset": offsets["pos_offset"], "byteLength": offsets["pos_len"], "target": 34962},
            {"buffer": 0, "byteOffset": offsets["norm_offset"], "byteLength": offsets["norm_len"], "target": 34962},
            {"buffer": 0, "byteOffset": offsets["idx_offset"], "byteLength": offsets["idx_len"], "target": 34963},
        ],
        "accessors": [
            {
                "bufferView": 0,
                "componentType": 5126,
                "count": 24,
                "type": "VEC3",
                "min": [-0.5, -0.5, -0.5],
                "max": [0.5, 0.5, 0.5],
            },
            {"bufferView": 1, "componentType": 5126, "count": 24, "type": "VEC3"},
            {"bufferView": 2, "componentType": 5123, "count": 36, "type": "SCALAR"},
        ],
        "extras": {
            "model_standard_strategy": "IFC4 semantic model + DXF mesh CAD exchange + glTF 2.0 runtime delivery + OGC 3D Tiles wrapper.",
            "total_objects": len(boxes),
            "licensed_beds": sum(1 for b in boxes if b.category == "bed"),
            "corridor_segments": sum(1 for b in boxes if b.category == "corridor"),
            "elevators": sum(1 for b in boxes if b.category == "elevator"),
        },
    }

    json_chunk = json.dumps(gltf, separators=(",", ":")).encode("utf-8")
    json_padding = (4 - len(json_chunk) % 4) % 4
    json_chunk += b" " * json_padding
    bin_padding = (4 - len(binary) % 4) % 4
    binary += b"\x00" * bin_padding
    total_len = 12 + 8 + len(json_chunk) + 8 + len(binary)
    glb = struct.pack("<III", 0x46546C67, 2, total_len)
    glb += struct.pack("<I4s", len(json_chunk), b"JSON") + json_chunk
    glb += struct.pack("<I4s", len(binary), b"BIN\x00") + binary
    (MODEL_DIR / "hospital_model.glb").write_bytes(glb)


def dxf_face_lines(points: list[tuple[float, float, float]], layer: str) -> list[str]:
    lines = ["0", "3DFACE", "8", layer]
    for idx, (x, y, z) in enumerate(points, start=1):
        lines += [f"{10 + idx - 1}", f"{x:.4f}", f"{20 + idx - 1}", f"{y:.4f}", f"{30 + idx - 1}", f"{z:.4f}"]
    return lines


def box_faces(box: Box) -> list[list[tuple[float, float, float]]]:
    # DXF is Z-up: map generator x,z plan to DXF x,y, and generator y to DXF z.
    x0, y0, z0, x1, y1, z1 = box.bounds
    pts = {
        "000": (x0, z0, y0),
        "001": (x0, z1, y0),
        "010": (x0, z0, y1),
        "011": (x0, z1, y1),
        "100": (x1, z0, y0),
        "101": (x1, z1, y0),
        "110": (x1, z0, y1),
        "111": (x1, z1, y1),
    }
    return [
        [pts["000"], pts["100"], pts["110"], pts["010"]],
        [pts["101"], pts["001"], pts["011"], pts["111"]],
        [pts["001"], pts["000"], pts["010"], pts["011"]],
        [pts["100"], pts["101"], pts["111"], pts["110"]],
        [pts["010"], pts["110"], pts["111"], pts["011"]],
        [pts["000"], pts["001"], pts["101"], pts["100"]],
    ]


def write_dxf(boxes: list[Box]) -> None:
    lines = [
        "0", "SECTION", "2", "HEADER",
        "9", "$ACADVER", "1", "AC1027",
        "9", "$INSUNITS", "70", "2",
        "0", "ENDSEC",
        "0", "SECTION", "2", "TABLES",
        "0", "TABLE", "2", "LAYER",
    ]
    for material in MATERIALS:
        lines += ["0", "LAYER", "2", material[:31], "70", "0", "62", "7", "6", "CONTINUOUS"]
    lines += ["0", "ENDTAB", "0", "ENDSEC", "0", "SECTION", "2", "ENTITIES"]
    for box in boxes:
        layer = box.material[:31]
        for face in box_faces(box):
            lines.extend(dxf_face_lines(face, layer))
    lines += ["0", "ENDSEC", "0", "EOF"]
    (CAD_DIR / "hospital_model.dxf").write_text("\n".join(lines) + "\n", encoding="utf-8")


def ifc_guid(seed: str) -> str:
    # IFC compressed GUID is specialized; use a stable 22-ish char opaque token acceptable for concept handoff.
    raw = uuid.uuid5(uuid.NAMESPACE_URL, seed).bytes
    return base64.urlsafe_b64encode(raw).decode("ascii").rstrip("=")[:22]


def write_ifc(boxes: list[Box]) -> None:
    entity_id = 1
    entities: list[str] = []

    def add(line: str) -> int:
        nonlocal entity_id
        current = entity_id
        entities.append(f"#{current}= {line};")
        entity_id += 1
        return current

    project = add("IFCPROJECT('0HOSPITALMODEL0000001',$,'500 Bed Tier 1 Trauma Academic Medical Center',$,$,$,$,$,$)")
    site = add("IFCSITE('1HOSPITALSITE0000001',$,'Academic Medical Center Campus',$,$,$,$,$,.ELEMENT.,$,$,$,$,$)")
    building = add("IFCBUILDING('2HOSPITALBLDG000001',$,'Main Hospital and Diagnostic Treatment Platform',$,$,$,$,$,.ELEMENT.,$,$,$)")
    add(f"IFCRELAGGREGATES('3RELPROJECTSITE0001',$,$,$,#{project},(#{site}))")
    add(f"IFCRELAGGREGATES('4RELSITEBLDG000001',$,$,$,#{site},(#{building}))")

    storey_ids = {}
    for floor, floor_code, program, _ in FLOOR_PROGRAM:
        storey = add(
            "IFCBUILDINGSTOREY("
            f"'{ifc_guid('storey:' + floor_code)}',$,'{sanitize_ifc(floor_code)} - {sanitize_ifc(program)}',$,$,$,$,$,.ELEMENT.,{floor_y(floor) * FT_TO_M:.4f})"
        )
        storey_ids[floor] = storey
    add(f"IFCRELAGGREGATES('{ifc_guid('building-storeys')}',$,$,$,#{building},({','.join('#' + str(storey_ids[f]) for f, *_ in FLOOR_PROGRAM)}))")

    by_floor: dict[int, list[int]] = {}
    for box in boxes:
        if box.category == "floor" or box.floor == 99:
            continue
        cls = "IFCSPACE" if box.category in {"patient_room", "care_unit", "emergency_department", "procedure_room"} else "IFCBUILDINGELEMENTPROXY"
        predefined = ".INTERNAL." if cls == "IFCSPACE" else "$"
        attrs = json.dumps(box.metadata, sort_keys=True)[:950].replace("'", "''")
        entity = add(
            f"{cls}('{ifc_guid(box.code)}',$,'{sanitize_ifc(box.code)}','{sanitize_ifc(box.name)}','{sanitize_ifc(attrs)}',$,$,$,{predefined})"
        )
        by_floor.setdefault(box.floor, []).append(entity)

    for floor, ids in sorted(by_floor.items()):
        storey = storey_ids.get(floor)
        if storey and ids:
            chunks = [ids[i:i + 80] for i in range(0, len(ids), 80)]
            for chunk_index, chunk in enumerate(chunks, start=1):
                add(f"IFCRELCONTAINEDINSPATIALSTRUCTURE('{ifc_guid(f'contain:{floor}:{chunk_index}')}',$,$,$,({','.join('#' + str(i) for i in chunk)}),#{storey})")

    for prefix, bank_name, elevator_class, count, capacity, stretcher, helipad, public in ELEVATOR_PROGRAM:
        for idx in range(1, count + 1):
            add(
                "IFCTRANSPORTELEMENT("
                f"'{ifc_guid(f'elevator:{prefix}:{idx}')}',$,'{prefix}-{idx:02d}',"
                f"'{sanitize_ifc(bank_name)}','class={elevator_class};capacity_lb={capacity};stretcher={stretcher};helipad={helipad};public={public}',$,$,$,.ELEVATOR.)"
            )

    generated_at = dt.datetime.now(dt.UTC).isoformat().replace("+00:00", "Z")
    header = f"""ISO-10303-21;
HEADER;
FILE_DESCRIPTION(('Concept BIM/CAD exchange model generated from planning DDL'),'2;1');
FILE_NAME('hospital_model.ifc','{generated_at}',('OpenAI Codex'),('Parthenon'),'Parthenon hospital CAD generator','Python stdlib','Concept only');
FILE_SCHEMA(('IFC4'));
ENDSEC;
DATA;
"""
    footer = "\nENDSEC;\nEND-ISO-10303-21;\n"
    (BIM_DIR / "hospital_model.ifc").write_text(header + "\n".join(entities) + footer, encoding="utf-8")


def write_tileset(boxes: list[Box]) -> None:
    bounds = [box.bounds for box in boxes]
    min_x = min(b[0] for b in bounds) * FT_TO_M
    min_y = min(b[1] for b in bounds) * FT_TO_M
    min_z = min(b[2] for b in bounds) * FT_TO_M
    max_x = max(b[3] for b in bounds) * FT_TO_M
    max_y = max(b[4] for b in bounds) * FT_TO_M
    max_z = max(b[5] for b in bounds) * FT_TO_M
    cx, cy, cz = (min_x + max_x) / 2, (min_y + max_y) / 2, (min_z + max_z) / 2
    hx, hy, hz = (max_x - min_x) / 2, (max_y - min_y) / 2, (max_z - min_z) / 2
    tileset = {
        "asset": {
            "version": "1.1",
            "tilesetVersion": "2026-06-25-concept",
            "gltfUpAxis": "Y",
        },
        "geometricError": 500,
        "root": {
            "boundingVolume": {
                "box": [cx, cy, cz, hx, 0, 0, 0, hy, 0, 0, 0, hz]
            },
            "geometricError": 0,
            "refine": "ADD",
            "content": {
                "uri": "../model/hospital_model.glb",
                "metadata": {
                    "name": "500-bed Level I trauma academic medical center concept GLB"
                },
            },
        },
        "schema": {
            "id": "HospitalPlanningModel",
            "classes": {
                "HospitalElement": {
                    "properties": {
                        "code": {"type": "STRING"},
                        "category": {"type": "STRING"},
                        "floor": {"type": "SCALAR", "componentType": "INT32"},
                    }
                }
            },
        },
    }
    (TILES_DIR / "tileset.json").write_text(json.dumps(tileset, indent=2) + "\n", encoding="utf-8")


def write_catalog(boxes: list[Box]) -> None:
    categories = {}
    floors = {}
    materials = {}
    for box in boxes:
        categories[box.category] = categories.get(box.category, 0) + 1
        floors[str(box.floor)] = floors.get(str(box.floor), 0) + 1
        materials[box.material] = materials.get(box.material, 0) + 1
    catalog = {
        "generated_at": dt.datetime.now(dt.UTC).isoformat().replace("+00:00", "Z"),
        "model_name": "500-bed Tier 1 / Level I trauma academic medical center",
        "standard_strategy": {
            "ifc": "IFC4 semantic BIM handoff for spatial hierarchy and assets.",
            "dxf": "DXF AC1027 3DFACE CAD exchange geometry.",
            "glb": "glTF 2.0 binary model for runtime navigation.",
            "3d_tiles": "OGC 3D Tiles 1.1 wrapper for scalable BIM/GIS streaming workflows.",
        },
        "summary": {
            "objects": len(boxes),
            "beds": sum(1 for b in boxes if b.category == "bed"),
            "patient_rooms": sum(1 for b in boxes if b.category == "patient_room"),
            "corridor_segments": sum(1 for b in boxes if b.category == "corridor"),
            "elevators": sum(1 for b in boxes if b.category == "elevator"),
            "ed_positions": sum(1 for b in boxes if b.category == "emergency_department"),
            "procedure_rooms": sum(1 for b in boxes if b.category == "procedure_room"),
        },
        "categories": categories,
        "floors": floors,
        "materials": materials,
        "materials_legend": MATERIALS,
        "objects": [
            {
                "code": box.code,
                "name": box.name,
                "category": box.category,
                "material": box.material,
                "floor": box.floor,
                "position_ft": {"x": box.x, "level": box.y, "z": box.z},
                "size_ft": {"x": box.sx, "y": box.sy, "z": box.sz},
                "metadata": box.metadata,
            }
            for box in boxes
        ],
    }
    (DATA_DIR / "model_catalog.json").write_text(json.dumps(catalog, indent=2) + "\n", encoding="utf-8")


def write_viewer() -> None:
    html = """<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>500 Bed Academic Medical Center CAD Navigator</title>
  <link rel="stylesheet" href="./styles.css">
</head>
<body>
  <main id="app">
    <canvas id="viewport" aria-label="3D hospital model"></canvas>
    <section id="toolbar" aria-label="Model controls">
      <div class="brand">
        <strong>Trauma I AMC</strong>
        <span id="modelCount">Loading model</span>
      </div>
      <div class="control-row">
        <label for="floorSelect">Floor</label>
        <select id="floorSelect">
          <option value="all">All floors</option>
        </select>
      </div>
      <div class="control-row">
        <label for="serviceSelect">Service</label>
        <select id="serviceSelect">
          <option value="all">All services</option>
        </select>
      </div>
      <div class="control-row search-row">
        <label for="searchInput">Find</label>
        <input id="searchInput" type="search" placeholder="bed, room, elevator">
      </div>
      <div class="tool-buttons">
        <button id="orbitButton" title="Orbit view" type="button"><i data-lucide="rotate-3d"></i></button>
        <button id="walkButton" title="Walk mode" type="button"><i data-lucide="footprints"></i></button>
        <button id="traumaButton" title="Trauma path" type="button"><i data-lucide="siren"></i></button>
        <button id="resetButton" title="Reset camera" type="button"><i data-lucide="home"></i></button>
      </div>
      <div id="toggles" class="toggles" aria-label="Layer toggles"></div>
    </section>
    <section id="inspector" aria-live="polite">
      <strong id="inspectorTitle">Select an element</strong>
      <dl id="inspectorData"></dl>
    </section>
    <section id="statusbar">
      <span id="statusText">Orbit to inspect. Walk mode supports WASD and pointer look.</span>
      <span id="cameraText"></span>
    </section>
  </main>
  <script async src="https://unpkg.com/es-module-shims@1.10.0/dist/es-module-shims.js"></script>
  <script type="importmap">
    {
      "imports": {
        "three": "https://unpkg.com/three@0.165.0/build/three.module.js",
        "three/addons/": "https://unpkg.com/three@0.165.0/examples/jsm/",
        "lucide": "https://unpkg.com/lucide@0.475.0/dist/esm/lucide.js"
      }
    }
  </script>
  <script type="module" src="./app.js"></script>
</body>
</html>
"""
    css = """* {
  box-sizing: border-box;
}

:root {
  color-scheme: dark;
  --bg: #171817;
  --panel: rgba(29, 31, 30, 0.92);
  --panel-solid: #222523;
  --line: rgba(238, 232, 214, 0.18);
  --text: #f4f0e7;
  --muted: #b7b2a7;
  --accent: #d99b43;
  --focus: #69b3c8;
  font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

html,
body,
#app {
  width: 100%;
  height: 100%;
  margin: 0;
  overflow: hidden;
  background: var(--bg);
  color: var(--text);
}

#viewport {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  display: block;
}

#toolbar,
#inspector,
#statusbar {
  position: fixed;
  border: 1px solid var(--line);
  background: var(--panel);
  backdrop-filter: blur(14px);
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
}

#toolbar {
  top: 16px;
  left: 16px;
  width: min(330px, calc(100vw - 32px));
  max-height: calc(100vh - 96px);
  overflow: auto;
  padding: 14px;
}

.brand {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.brand strong {
  font-size: 16px;
  letter-spacing: 0;
}

.brand span,
#statusbar,
#inspectorData {
  color: var(--muted);
  font-size: 12px;
}

.control-row {
  display: grid;
  grid-template-columns: 68px 1fr;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
}

label {
  color: var(--muted);
  font-size: 12px;
}

select,
input {
  width: 100%;
  min-width: 0;
  height: 34px;
  border: 1px solid var(--line);
  background: var(--panel-solid);
  color: var(--text);
  padding: 0 9px;
  outline: none;
  border-radius: 6px;
}

select:focus,
input:focus,
button:focus-visible {
  border-color: var(--focus);
  box-shadow: 0 0 0 2px rgba(105, 179, 200, 0.25);
}

.tool-buttons {
  display: grid;
  grid-template-columns: repeat(4, 42px);
  gap: 8px;
  margin: 12px 0;
}

button {
  width: 42px;
  height: 38px;
  border: 1px solid var(--line);
  background: #2b2e2c;
  color: var(--text);
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

button.active {
  border-color: var(--accent);
  background: #3a2d1d;
  color: #ffd99c;
}

button svg {
  width: 18px;
  height: 18px;
}

.toggles {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 7px;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 7px;
  min-height: 32px;
  padding: 6px 8px;
  border: 1px solid var(--line);
  background: rgba(255, 255, 255, 0.03);
  border-radius: 6px;
  color: var(--muted);
  font-size: 12px;
}

.toggle input {
  width: 14px;
  height: 14px;
  accent-color: var(--accent);
}

#inspector {
  right: 16px;
  bottom: 48px;
  width: min(360px, calc(100vw - 32px));
  max-height: min(440px, calc(100vh - 140px));
  overflow: auto;
  padding: 14px;
}

#inspectorTitle {
  display: block;
  margin-bottom: 10px;
  font-size: 15px;
}

#inspectorData {
  display: grid;
  grid-template-columns: minmax(88px, 0.45fr) 1fr;
  gap: 6px 10px;
  margin: 0;
}

#inspectorData dt {
  color: #ded7c8;
}

#inspectorData dd {
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}

#statusbar {
  left: 16px;
  right: 16px;
  bottom: 12px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 10px;
}

@media (max-width: 720px) {
  #toolbar {
    top: 8px;
    left: 8px;
    right: 8px;
    width: auto;
    max-height: 43vh;
    padding: 10px;
  }

  .brand {
    margin-bottom: 6px;
  }

  .control-row {
    grid-template-columns: 56px 1fr;
    margin: 6px 0;
  }

  .toggles {
    grid-template-columns: 1fr 1fr;
  }

  .tool-buttons {
    grid-template-columns: repeat(4, 38px);
  }

  button {
    width: 38px;
    height: 34px;
  }

  #inspector {
    left: 8px;
    right: 8px;
    bottom: 42px;
    width: auto;
    max-height: 28vh;
    padding: 10px;
  }

  #statusbar {
    left: 8px;
    right: 8px;
    bottom: 8px;
  }

  #cameraText {
    display: none;
  }
}
"""
    js = """import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { PointerLockControls } from 'three/addons/controls/PointerLockControls.js';
import { createIcons, icons } from 'lucide';

createIcons({ icons });

const canvas = document.querySelector('#viewport');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.outputColorSpace = THREE.SRGBColorSpace;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0x171817);
scene.fog = new THREE.Fog(0x171817, 140, 420);

const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 1200);
camera.position.set(92, 95, 155);

const hemi = new THREE.HemisphereLight(0xf6f1e8, 0x3d423e, 2.1);
scene.add(hemi);
const sun = new THREE.DirectionalLight(0xfff4df, 2.0);
sun.position.set(-80, 160, 90);
scene.add(sun);

const grid = new THREE.GridHelper(180, 18, 0x85765e, 0x383b37);
grid.position.y = -0.15;
scene.add(grid);

const orbit = new OrbitControls(camera, renderer.domElement);
orbit.target.set(0, 46, 0);
orbit.enableDamping = true;
orbit.maxPolarAngle = Math.PI * 0.49;
orbit.minDistance = 18;
orbit.maxDistance = 360;

const walk = new PointerLockControls(camera, renderer.domElement);
const raycaster = new THREE.Raycaster();
const pointer = new THREE.Vector2();
const clock = new THREE.Clock();
const keys = new Set();
const allObjects = [];
const categories = new Map();
const services = new Set();
const floors = new Set();
let activeFloor = 'all';
let activeService = 'all';
let traumaOnly = false;
let selected = null;
let modelRoot = null;

const floorSelect = document.querySelector('#floorSelect');
const serviceSelect = document.querySelector('#serviceSelect');
const searchInput = document.querySelector('#searchInput');
const toggles = document.querySelector('#toggles');
const modelCount = document.querySelector('#modelCount');
const statusText = document.querySelector('#statusText');
const cameraText = document.querySelector('#cameraText');
const inspectorTitle = document.querySelector('#inspectorTitle');
const inspectorData = document.querySelector('#inspectorData');
const orbitButton = document.querySelector('#orbitButton');
const walkButton = document.querySelector('#walkButton');
const traumaButton = document.querySelector('#traumaButton');
const resetButton = document.querySelector('#resetButton');

function setStatus(text) {
  statusText.textContent = text;
}

function flattenMetadata(data) {
  const entries = [];
  for (const [key, value] of Object.entries(data || {})) {
    if (value === undefined || value === null || value === '') continue;
    let display = value;
    if (typeof value === 'boolean') display = value ? 'yes' : 'no';
    if (typeof value === 'object') display = JSON.stringify(value);
    entries.push([key.replaceAll('_', ' '), String(display)]);
  }
  return entries.slice(0, 18);
}

function showInspector(object) {
  selected = object;
  if (!object) {
    inspectorTitle.textContent = 'Select an element';
    inspectorData.innerHTML = '';
    return;
  }
  const data = object.userData || {};
  inspectorTitle.textContent = data.name || object.name;
  const rows = flattenMetadata(data);
  inspectorData.innerHTML = rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');
}

function categoryLabel(category) {
  return category
    .replaceAll('_', ' ')
    .replace(/\\b\\w/g, char => char.toUpperCase());
}

function addToggle(category) {
  const label = document.createElement('label');
  label.className = 'toggle';
  const input = document.createElement('input');
  input.type = 'checkbox';
  input.checked = true;
  input.dataset.category = category;
  input.addEventListener('change', applyFilters);
  const span = document.createElement('span');
  span.textContent = categoryLabel(category);
  label.append(input, span);
  toggles.append(label);
}

function selectedCategories() {
  return new Set([...toggles.querySelectorAll('input[type="checkbox"]')]
    .filter(input => input.checked)
    .map(input => input.dataset.category));
}

function objectMatchesSearch(object) {
  const query = searchInput.value.trim().toLowerCase();
  if (!query) return true;
  const data = object.userData || {};
  return [object.name, data.code, data.name, data.category, data.service_line, data.unit_code, data.elevator_class]
    .filter(Boolean)
    .some(value => String(value).toLowerCase().includes(query));
}

function applyFilters() {
  const cats = selectedCategories();
  let visibleCount = 0;
  for (const object of allObjects) {
    const data = object.userData || {};
    const floorOk = activeFloor === 'all' || String(data.floor) === activeFloor || data.category === 'elevator';
    const serviceOk = activeService === 'all' || data.service_line === activeService;
    const traumaOk = !traumaOnly || data.trauma_priority || data.elevator_class === 'trauma_priority' || data.flow_class === 'emergency_response' || String(data.code || '').includes('TRAUMA') || String(data.name || '').toLowerCase().includes('trauma');
    const categoryOk = cats.has(data.category);
    const searchOk = objectMatchesSearch(object);
    object.visible = floorOk && serviceOk && traumaOk && categoryOk && searchOk;
    if (object.visible) visibleCount += 1;
  }
  modelCount.textContent = `${visibleCount.toLocaleString()} visible`;
}

function frameObject(object) {
  const box = new THREE.Box3().setFromObject(object);
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const radius = Math.max(size.x, size.y, size.z, 12);
  orbit.target.copy(center);
  camera.position.set(center.x + radius * 1.8, center.y + radius * 1.3, center.z + radius * 1.8);
  orbit.update();
}

function selectBySearch() {
  const query = searchInput.value.trim().toLowerCase();
  if (!query) {
    applyFilters();
    return;
  }
  const match = allObjects.find(object => {
    const data = object.userData || {};
    return [data.code, data.name, data.unit_code, data.elevator_class]
      .filter(Boolean)
      .some(value => String(value).toLowerCase().includes(query));
  });
  if (match) {
    showInspector(match);
    frameObject(match);
    setStatus(`Focused ${match.userData.code || match.name}`);
  }
  applyFilters();
}

function resetCamera() {
  camera.position.set(92, 95, 155);
  orbit.target.set(0, 46, 0);
  orbit.enabled = true;
  orbit.update();
  setStatus('Camera reset');
}

function enableOrbit() {
  if (document.pointerLockElement) document.exitPointerLock();
  orbit.enabled = true;
  orbitButton.classList.add('active');
  walkButton.classList.remove('active');
  setStatus('Orbit mode');
}

function enableWalk() {
  orbit.enabled = false;
  walk.lock();
  walkButton.classList.add('active');
  orbitButton.classList.remove('active');
  setStatus('Walk mode');
}

function updateCameraText() {
  cameraText.textContent = `x ${camera.position.x.toFixed(0)} y ${camera.position.y.toFixed(0)} z ${camera.position.z.toFixed(0)}`;
}

function loadModel() {
  const loader = new GLTFLoader();
  loader.load('../model/hospital_model.glb', gltf => {
    modelRoot = gltf.scene;
    scene.add(modelRoot);
    modelRoot.traverse(object => {
      if (!object.isMesh) return;
      object.castShadow = false;
      object.receiveShadow = true;
      const data = object.userData || {};
      allObjects.push(object);
      if (data.category) categories.set(data.category, (categories.get(data.category) || 0) + 1);
      if (data.service_line) services.add(data.service_line);
      if (Number.isFinite(data.floor) || typeof data.floor === 'number') floors.add(String(data.floor));
    });
    [...floors].sort((a, b) => Number(a) - Number(b)).forEach(floor => {
      const option = document.createElement('option');
      option.value = floor;
      option.textContent = floor === '99' ? 'Vertical' : `Floor ${floor}`;
      floorSelect.append(option);
    });
    [...services].sort().forEach(service => {
      const option = document.createElement('option');
      option.value = service;
      option.textContent = service.replaceAll('_', ' ');
      serviceSelect.append(option);
    });
    [...categories.keys()].sort().forEach(addToggle);
    modelCount.textContent = `${allObjects.length.toLocaleString()} objects`;
    applyFilters();
    setStatus('Model loaded');
  }, undefined, error => {
    console.error(error);
    setStatus('Model failed to load');
  });
}

function onPointerDown(event) {
  if (!modelRoot || event.target !== renderer.domElement || document.pointerLockElement) return;
  pointer.x = (event.clientX / window.innerWidth) * 2 - 1;
  pointer.y = -(event.clientY / window.innerHeight) * 2 + 1;
  raycaster.setFromCamera(pointer, camera);
  const hits = raycaster.intersectObjects(allObjects.filter(object => object.visible), false);
  if (hits.length) {
    showInspector(hits[0].object);
    setStatus(`Selected ${hits[0].object.userData.code || hits[0].object.name}`);
  }
}

function animate() {
  requestAnimationFrame(animate);
  const delta = Math.min(clock.getDelta(), 0.05);
  if (document.pointerLockElement) {
    const speed = 55 * delta;
    if (keys.has('KeyW')) walk.moveForward(speed);
    if (keys.has('KeyS')) walk.moveForward(-speed);
    if (keys.has('KeyA')) walk.moveRight(-speed);
    if (keys.has('KeyD')) walk.moveRight(speed);
    if (keys.has('KeyQ')) camera.position.y -= speed;
    if (keys.has('KeyE')) camera.position.y += speed;
  } else {
    orbit.update();
  }
  updateCameraText();
  renderer.render(scene, camera);
}

floorSelect.addEventListener('change', event => {
  activeFloor = event.target.value;
  applyFilters();
});
serviceSelect.addEventListener('change', event => {
  activeService = event.target.value;
  applyFilters();
});
searchInput.addEventListener('input', () => {
  window.clearTimeout(searchInput._timer);
  searchInput._timer = window.setTimeout(selectBySearch, 160);
});
orbitButton.addEventListener('click', enableOrbit);
walkButton.addEventListener('click', enableWalk);
traumaButton.addEventListener('click', () => {
  traumaOnly = !traumaOnly;
  traumaButton.classList.toggle('active', traumaOnly);
  applyFilters();
  setStatus(traumaOnly ? 'Trauma path isolated' : 'All paths restored');
});
resetButton.addEventListener('click', resetCamera);
window.addEventListener('pointerdown', onPointerDown);
window.addEventListener('keydown', event => keys.add(event.code));
window.addEventListener('keyup', event => keys.delete(event.code));
window.addEventListener('resize', () => {
  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
});
document.addEventListener('pointerlockchange', () => {
  if (!document.pointerLockElement) enableOrbit();
});

enableOrbit();
loadModel();
animate();
"""
    (VIEWER_DIR / "index.html").write_text(html, encoding="utf-8")
    (VIEWER_DIR / "styles.css").write_text(css, encoding="utf-8")
    (VIEWER_DIR / "app.js").write_text(js, encoding="utf-8")


def write_readme(boxes: list[Box]) -> None:
    summary = {
        "objects": len(boxes),
        "beds": sum(1 for b in boxes if b.category == "bed"),
        "patient_rooms": sum(1 for b in boxes if b.category == "patient_room"),
        "corridors": sum(1 for b in boxes if b.category == "corridor"),
        "elevators": sum(1 for b in boxes if b.category == "elevator"),
        "ed_positions": sum(1 for b in boxes if b.category == "emergency_department"),
        "procedure_rooms": sum(1 for b in boxes if b.category == "procedure_room"),
    }
    generated_date = dt.datetime.now(dt.UTC).date().isoformat()
    readme = f"""# 500-Bed Level I Trauma Academic Medical Center CAD Model

Generated: {generated_date}

This directory contains a concept-level CAD/BIM and navigable web model for the
500-bed Tier 1 / ACS Level I trauma academic medical center planning model.

## Standards Strategy

- **IFC4** is used for BIM semantics and asset/spatial hierarchy. buildingSMART
  identifies IFC as the open, vendor-neutral ISO standard for digital
  descriptions of buildings and infrastructure.
- **DXF AC1027** is included as a conventional CAD mesh exchange file using
  layer-separated 3DFACE geometry.
- **glTF 2.0 / GLB** is used for the navigable browser model because Khronos
  positions glTF as an efficient runtime delivery format for 3D scenes.
- **OGC 3D Tiles 1.1** is included as a single-tile wrapper for scalable BIM/GIS
  streaming workflows.
- **Catalog JSON** keeps searchable object metadata alongside the geometry.

## Files

- `bim/hospital_model.ifc` - IFC4 semantic model.
- `cad/hospital_model.dxf` - CAD exchange mesh model.
- `model/hospital_model.glb` - binary glTF model used by the viewer.
- `3dtiles/tileset.json` - 3D Tiles wrapper for the GLB payload.
- `data/model_catalog.json` - searchable object and standards metadata.
- `viewer/index.html` - Three.js CAD navigator.
- `generate_hospital_cad_model.py` - deterministic generator.

## Model Counts

```json
{json.dumps(summary, indent=2)}
```

## Scope

This is a detailed concept model, not a stamped construction document. It is
intended for planning, simulation, service-line adjacency review, digital-twin
prototyping, and standards-driven discussion. A construction-ready model would
need licensed architecture/engineering authoring, equipment vendor families,
structural/MEP coordination, code review, clash detection, commissioning data,
and AHJ approval.

## Regenerate

```bash
python3 docs/research/hospital-cad-model/generate_hospital_cad_model.py
```

## Verify Viewer

Start a local server from this directory:

```bash
python3 -m http.server 8765 --bind 127.0.0.1
```

Then run:

```bash
PLAYWRIGHT_MODULE=file:///path/to/playwright/index.js node docs/research/hospital-cad-model/verify_viewer.mjs
```

The verifier writes `verification/results.json` plus desktop and mobile
screenshots, and checks that the WebGL canvas is nonblank.
"""
    (OUT / "README.md").write_text(readme, encoding="utf-8")


def main() -> None:
    ensure_dirs()
    boxes = generate_boxes()
    if sum(1 for b in boxes if b.category == "bed") != 500:
        raise RuntimeError("Expected exactly 500 generated beds")
    write_glb(boxes)
    write_dxf(boxes)
    write_ifc(boxes)
    write_tileset(boxes)
    write_catalog(boxes)
    write_viewer()
    write_readme(boxes)
    print(json.dumps({
        "objects": len(boxes),
        "beds": sum(1 for b in boxes if b.category == "bed"),
        "patient_rooms": sum(1 for b in boxes if b.category == "patient_room"),
        "corridor_segments": sum(1 for b in boxes if b.category == "corridor"),
        "elevators": sum(1 for b in boxes if b.category == "elevator"),
        "ed_positions": sum(1 for b in boxes if b.category == "emergency_department"),
        "procedure_rooms": sum(1 for b in boxes if b.category == "procedure_room"),
    }, indent=2))


if __name__ == "__main__":
    main()
