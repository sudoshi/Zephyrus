import json
from datetime import datetime
import pandas as pd

def convert_to_ocel(admission_data):
    """
    Convert admission process data to OCEL 2.0 format
    """
    
    # Define object types for admission process
    object_types = {
        "admission": {
            "attributes": ["admission_type", "priority"]
        },
        "patient": {
            "attributes": ["id", "age_group", "acuity"]
        },
        "bed": {
            "attributes": ["unit", "room_number", "bed_type"]
        },
        "staff": {
            "attributes": ["role", "shift"]
        }
    }

    # Initialize OCEL structure
    ocel = {
        "ocel:global-log": {
            "ocel:version": "2.0",
            "ocel:ordering": "timestamp",
            "ocel:attribute-names": [
                "admission_type",
                "priority",
                "unit",
                "duration_mins",
                "resource"
            ],
            "ocel:object-types": list(object_types.keys())
        },
        "ocel:events": {},
        "ocel:objects": {},
        "ocel:object-changes": []
    }

    # Process events
    for idx, event in enumerate(admission_data):
        event_id = f"e{idx}"
        
        # Create event entry
        ocel["ocel:events"][event_id] = {
            "ocel:activity": event["activity"],
            "ocel:timestamp": event["timestamp"],
            "ocel:vmap": {
                "admission_type": event["admission_type"],
                "unit": event["unit"],
                "duration_mins": event["duration_mins"],
                "resource": event["resource"]
            }
        }

        # Create related objects
        admission_id = event["case_id"]
        if admission_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][admission_id] = {
                "ocel:type": "admission",
                "ocel:ovmap": {
                    "admission_type": event["admission_type"],
                    "priority": "routine" if event["admission_type"] == "Direct Admit" else "urgent"
                }
            }

        # Create patient object if it doesn't exist
        patient_id = f"P{admission_id[1:]}"  # Convert A001 to P001
        if patient_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][patient_id] = {
                "ocel:type": "patient",
                "ocel:ovmap": {
                    "id": patient_id,
                    "acuity": "high" if event["admission_type"] in ["Emergency Department", "Transfer"] else "normal"
                }
            }

        # Create bed object when bed assignment occurs
        if event["activity"] == "Bed Assignment":
            bed_id = f"B{event['unit'][:1]}{admission_id[1:]}"  # e.g., BM001 for Medical unit
            ocel["ocel:objects"][bed_id] = {
                "ocel:type": "bed",
                "ocel:ovmap": {
                    "unit": event["unit"],
                    "bed_type": "isolation" if event["admission_type"] == "Transfer" else "standard"
                }
            }

            # Record object change for bed assignment
            ocel["ocel:object-changes"].append({
                "ocel:timestamp": event["timestamp"],
                "ocel:type": "bed",
                "ocel:oid": bed_id,
                "ocel:change": "assign",
                "ocel:value": admission_id
            })

        # Create staff object
        staff_id = event["resource"]
        if staff_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][staff_id] = {
                "ocel:type": "staff",
                "ocel:ovmap": {
                    "role": "nurse" if "Nurse" in staff_id else "physician",
                    "shift": "day" if 8 <= datetime.fromisoformat(event["timestamp"]).hour < 20 else "night"
                }
            }

    return ocel

def export_ocel(ocel_data, filename="admission_process.jsonocel"):
    """Export OCEL data to JSON file"""
    with open(filename, 'w') as f:
        json.dump(ocel_data, f, indent=2)

# Example usage:
"""
admission_data = [
    {
        "case_id": "A001",
        "activity": "Patient Arrival",
        "timestamp": "2024-02-17T08:30:00",
        "unit": "Medical",
        "resource": "Staff_01",
        "duration_mins": 15,
        "admission_type": "Direct Admit"
    },
    # ... more events
]

ocel_data = convert_to_ocel(admission_data)
export_ocel(ocel_data)
"""