import json
from datetime import datetime
import pandas as pd

def convert_discharge_to_ocel(discharge_data):
    """
    Convert discharge process data to OCEL 2.0 format
    """
    
    # Define object types for discharge process
    object_types = {
        "discharge": {
            "attributes": ["disposition", "status", "estimated_time"]
        },
        "patient": {
            "attributes": ["id", "length_of_stay", "acuity"]
        },
        "medication": {
            "attributes": ["type", "reconciliation_status"]
        },
        "transportation": {
            "attributes": ["type", "status", "scheduled_time"]
        },
        "staff": {
            "attributes": ["role", "shift", "department"]
        },
        "documentation": {
            "attributes": ["type", "status", "completion_time"]
        }
    }

    # Initialize OCEL structure
    ocel = {
        "ocel:global-log": {
            "ocel:version": "2.0",
            "ocel:ordering": "timestamp",
            "ocel:attribute-names": [
                "disposition",
                "unit",
                "duration_mins",
                "resource",
                "status",
                "priority"
            ],
            "ocel:object-types": list(object_types.keys())
        },
        "ocel:events": {},
        "ocel:objects": {},
        "ocel:object-changes": []
    }

    # Process events
    for idx, event in enumerate(discharge_data):
        event_id = f"e{idx}"
        
        # Create event entry
        ocel["ocel:events"][event_id] = {
            "ocel:activity": event["activity"],
            "ocel:timestamp": event["timestamp"],
            "ocel:vmap": {
                "disposition": event["disposition"],
                "unit": event["unit"],
                "duration_mins": event["duration_mins"],
                "resource": event["resource"]
            }
        }

        # Create related objects
        discharge_id = event["case_id"]
        if discharge_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][discharge_id] = {
                "ocel:type": "discharge",
                "ocel:ovmap": {
                    "disposition": event["disposition"],
                    "status": "in_progress",
                    "estimated_time": event["timestamp"]  # Initial estimate
                }
            }

        # Create patient object if it doesn't exist
        patient_id = f"P{discharge_id[1:]}"  # Convert D001 to P001
        if patient_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][patient_id] = {
                "ocel:type": "patient",
                "ocel:ovmap": {
                    "id": patient_id,
                    "acuity": "high" if event["disposition"] in ["Skilled Nursing Facility", "Rehabilitation Facility"] else "normal"
                }
            }

        # Create medication object when medication reconciliation occurs
        if event["activity"] == "Medication Reconciliation":
            med_id = f"M{discharge_id[1:]}"
            ocel["ocel:objects"][med_id] = {
                "ocel:type": "medication",
                "ocel:ovmap": {
                    "type": "discharge_meds",
                    "reconciliation_status": "completed"
                }
            }

            # Record object change for medication reconciliation
            ocel["ocel:object-changes"].append({
                "ocel:timestamp": event["timestamp"],
                "ocel:type": "medication",
                "ocel:oid": med_id,
                "ocel:change": "reconcile",
                "ocel:value": "completed"
            })

        # Create transportation object when transport is arranged
        if event["activity"] == "Patient Transport Arranged":
            transport_id = f"T{discharge_id[1:]}"
            ocel["ocel:objects"][transport_id] = {
                "ocel:type": "transportation",
                "ocel:ovmap": {
                    "type": "wheelchair" if event["disposition"] == "Home" else "stretcher",
                    "status": "scheduled",
                    "scheduled_time": event["timestamp"]
                }
            }

        # Create documentation object for discharge summary
        if event["activity"] == "Discharge Summary Documentation":
            doc_id = f"DOC{discharge_id[1:]}"
            ocel["ocel:objects"][doc_id] = {
                "ocel:type": "documentation",
                "ocel:ovmap": {
                    "type": "discharge_summary",
                    "status": "completed",
                    "completion_time": event["timestamp"]
                }
            }

        # Create staff object
        staff_id = event["resource"]
        if staff_id not in ocel["ocel:objects"]:
            ocel["ocel:objects"][staff_id] = {
                "ocel:type": "staff",
                "ocel:ovmap": {
                    "role": determine_staff_role(event["activity"]),
                    "shift": "day" if 8 <= datetime.fromisoformat(event["timestamp"]).hour < 20 else "night",
                    "department": event["unit"]
                }
            }

        # Update discharge status on physical discharge
        if event["activity"] == "Physical Discharge":
            ocel["ocel:object-changes"].append({
                "ocel:timestamp": event["timestamp"],
                "ocel:type": "discharge",
                "ocel:oid": discharge_id,
                "ocel:change": "status",
                "ocel:value": "completed"
            })

    return ocel

def determine_staff_role(activity):
    """Determine staff role based on activity"""
    role_mapping = {
        "Discharge Order Written": "physician",
        "Medication Reconciliation": "pharmacist",
        "Patient Education": "nurse",
        "Final Nursing Assessment": "nurse",
        "Discharge Summary Documentation": "physician",
        "Patient Transport": "transport",
        "Social Work Consult": "social_worker"
    }
    return role_mapping.get(activity, "staff")

def export_ocel(ocel_data, filename="discharge_process.jsonocel"):
    """Export OCEL data to JSON file"""
    with open(filename, 'w') as f:
        json.dump(ocel_data, f, indent=2)

# Example of validation function
def validate_ocel_discharge(ocel_data):
    """Validate OCEL discharge data structure and content"""
    validation_results = {
        "valid": True,
        "errors": [],
        "warnings": []
    }
    
    # Check required OCEL structure
    required_sections = ["ocel:global-log", "ocel:events", "ocel:objects", "ocel:object-changes"]
    for section in required_sections:
        if section not in ocel_data:
            validation_results["valid"] = False
            validation_results["errors"].append(f"Missing required section: {section}")
    
    # Check events have required attributes
    for event_id, event in ocel_data.get("ocel:events", {}).items():
        required_event_attrs = ["ocel:activity", "ocel:timestamp", "ocel:vmap"]
        for attr in required_event_attrs:
            if attr not in event:
                validation_results["errors"].append(f"Event {event_id} missing {attr}")
                validation_results["valid"] = False
    
    # Check object types are valid
    valid_object_types = ["discharge", "patient", "medication", "transportation", "staff", "documentation"]
    for obj_id, obj in ocel_data.get("ocel:objects", {}).items():
        if obj.get("ocel:type") not in valid_object_types:
            validation_results["warnings"].append(f"Unknown object type for {obj_id}: {obj.get('ocel:type')}")
    
    return validation_results

# Example usage:
"""
discharge_data = [
    {
        "case_id": "D001",
        "activity": "Discharge Order Written",
        "timestamp": "2024-02-17T10:30:00",
        "unit": "Medical",
        "resource": "Staff_01",
        "duration_mins": 15,
        "disposition": "Home"
    },
    # ... more events
]

ocel_data = convert_discharge_to_ocel(discharge_data)
validation_results = validate_ocel_discharge(ocel_data)
if validation_results["valid"]:
    export_ocel(ocel_data)
else:
    print("Validation errors:", validation_results["errors"])
"""