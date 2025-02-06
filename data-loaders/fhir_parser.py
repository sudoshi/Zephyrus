python
Copy
#!/usr/bin/env python3

import psycopg2
import logging
from typing import Dict, Any, Optional
import json
from datetime import datetime, timezone

# Configure module logger
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# -----------------------------------------------------------------------------
# 1. Database connection config
# -----------------------------------------------------------------------------
DB_CONFIG = {
    "host": "localhost",
    "port": 5432,
    "dbname": "my_fhir_db",
    "user": "postgres",
    "password": "mysecretpassword"
}

# -----------------------------------------------------------------------------
# 2. Helper functions for date/time
# -----------------------------------------------------------------------------
def parse_datetime(dt_str: Optional[str]) -> Optional[str]:
    """
    Convert a FHIR datetime string (e.g., '2023-07-02T13:45:00Z')
    into a Postgres-compatible ISO8601 or None if invalid.
    """
    if not dt_str:
        return None
    try:
        # Replace 'Z' with UTC offset so datetime.fromisoformat works
        dt_str_clean = dt_str.replace("Z", "+00:00")
        dt = datetime.fromisoformat(dt_str_clean)
        # Return a string in ISO8601 with timezone
        return dt.isoformat()
    except ValueError:
        logger.warning(f"Invalid datetime format: {dt_str}")
        return None

# -----------------------------------------------------------------------------
# 3. Core parser logic
# -----------------------------------------------------------------------------
def parse_patient(resource_json: Dict[str, Any]) -> Dict[str, Any]:
    """
    Example function to parse a FHIR Patient resource JSON.
    However, your domain schema does NOT have a direct 'patient' table.
    Instead, the resource is stored in fhir.fhir_resource, and references
    (patient_id) in other tables use that resource's ID.

    So, for a plain 'Patient', there's often no direct domain insert here.
    We'll return an empty dict or some fields if we want.
    """
    patient_data = {}
    try:
        # Common fields in a FHIR Patient
        patient_id = resource_json.get("id")
        # Possibly we do something with birthDate, gender, name, etc.
        # but no direct table to store them (except fhir_resource).
        logger.debug(f"Parsed Patient resource: {patient_id}")
    except Exception as e:
        logger.error(f"parse_patient error: {e}")
    return patient_data  # Probably unused in your domain, unless needed


def parse_encounter(resource_json: Dict[str, Any]) -> Dict[str, Any]:
    """
    Parse a FHIR Encounter resource. We guess if it's an ED vs. inpatient
    based on 'class.code' or 'class.system' or 'type[].coding[]'.

    We'll return a dictionary with the domain table name + the relevant fields
    for that table. Example: if we see 'class=EMER', we treat it as an ED visit;
    if 'class=IMP', we treat it as an admission, etc.
    """
    try:
        enc_id = resource_json.get("id")
        encounter_class = resource_json.get("class", {}).get("code", "").lower()
        start_dt = parse_datetime(resource_json.get("period", {}).get("start"))
        end_dt = parse_datetime(resource_json.get("period", {}).get("end"))

        # The FHIR resource references a Patient by "subject.reference = 'Patient/xxx'"
        patient_ref = resource_json.get("subject", {}).get("reference", "")
        # Extract the actual UUID from 'Patient/xxxxx'
        patient_uuid = patient_ref.replace("Patient/", "")

        # Domain table selection
        if encounter_class in ("emergency", "emer"):
            # We'll treat as ED visit
            return {
                "domain_table": "ed_visit",
                "admission_id": None,  # We'll create a new 'patient_admission' row if needed (see below)
                "arrival_time": start_dt,
                "departure_time": end_dt,
                # Additional fields in ed_visit:
                # triage_time, provider_time, disposition_time, etc. not always in FHIR
                # We'll store some placeholders or skip them
                "acuity_level": 3,  # Hard-coded for example
                "chief_complaint": None,  # Could parse from 'reasonCode'
                "status": "ACTIVE",  # or from resource_json.get('status')
                "fhir_encounter_id": enc_id,   # we store the resource ID if needed
                "patient_id": patient_uuid
            }
        elif encounter_class in ("inpatient", "imp"):
            # We'll treat as admission
            return {
                "domain_table": "patient_admission",
                "patient_id": patient_uuid,
                "encounter_id": enc_id,  # store the FHIR Encounter ID
                "admission_time": start_dt,
                "discharge_time": end_dt,
                "admission_type": "INPATIENT",  # or parse from 'type[].coding[].code'
                "admission_source": "UNKNOWN",  # no direct FHIR field, can parse 'hospitalization.admitSource'
                "status": "ADMITTED"  # or parse from resource_json.get('status')
            }
        else:
            logger.info(f"Encounter {enc_id} has unknown class: {encounter_class}. No domain table mapped.")
            return {}
    except Exception as e:
        logger.error(f"parse_encounter error: {e}")
        return {}


def parse_observation(resource_json: Dict[str, Any]) -> Dict[str, Any]:
    """
    Example parse for an Observation. Your domain includes many possible
    places an Observation could go (e.g., icu_monitoring, rehab_evaluation, etc.).
    You would need to decide based on codes, categories, or your own logic.

    We'll do a trivial example: if 'category=Vital Signs' => insert into icu_monitoring,
    if 'category=therapy' => rehab_evaluation, etc.
    This is a big assumption. Adjust as needed.
    """
    try:
        obs_id = resource_json.get("id")
        category_code = ""
        cat_list = resource_json.get("category", [])
        if cat_list and isinstance(cat_list, list):
            first_cat = cat_list[0]
            category_code = first_cat.get("coding", [{}])[0].get("code", "").lower()

        obs_time = parse_datetime(resource_json.get("effectiveDateTime"))
        patient_ref = resource_json.get("subject", {}).get("reference", "")
        patient_uuid = patient_ref.replace("Patient/", "")

        if "vital" in category_code:
            # We'll store in `icu_monitoring` as a simple example
            return {
                "domain_table": "icu_monitoring",
                "admission_id": None,  # We need to find an admission row (patient_admission) for that patient
                "monitoring_time": obs_time,
                "vital_signs": resource_json.get("valueQuantity", {}),  # This is naive
                "fhir_observation_id": obs_id
            }
        else:
            logger.info(f"Observation {obs_id} category '{category_code}' not mapped to a domain table.")
            return {}
    except Exception as e:
        logger.error(f"parse_observation error: {e}")
        return {}

# -----------------------------------------------------------------------------
# 4. Main parser function
# -----------------------------------------------------------------------------
def parse_resource(resource_type: str, resource_json: Dict[str, Any]) -> Dict[str, Any]:
    """
    Route the resource to the correct parser, then return a dictionary of
    the fields to insert plus the domain table name.
    """
    resource_type_lower = resource_type.lower()

    if resource_type_lower == "patient":
        # Typically no direct domain table, so we might do nothing.
        return parse_patient(resource_json)
    elif resource_type_lower == "encounter":
        return parse_encounter(resource_json)
    elif resource_type_lower == "observation":
        return parse_observation(resource_json)
    # Add more as needed: "MedicationRequest", "Procedure", etc.

    # Default no-op
    logger.debug(f"No parse logic for resource_type={resource_type}")
    return {}


# -----------------------------------------------------------------------------
# 5. Storing Data in the fhir schema
# -----------------------------------------------------------------------------
def upsert_ed_visit(conn, data: Dict[str, Any]) -> None:
    """
    Insert or update row in fhir.ed_visit table, referencing a patient_admission row (if needed).
    We'll assume we must have a row in patient_admission first, but that might require logic.
    """
    # We need an admission row to reference? In your schema, `ed_visit.admission_id` references
    # patient_admission(id). So we either create or find an existing admission row for this patient.
    if not data.get("patient_id"):
        return

    # Make a simplified approach: see if there's already a patient_admission for this Encounter
    # We'll make a quick function find_or_create_admission for that
    admission_id = find_or_create_admission_for_ed(conn, data)
    if not admission_id:
        logger.error(f"Cannot create ED visit because no admission_id was found/created.")
        return

    sql = """
      INSERT INTO fhir.ed_visit(
        id, admission_id, arrival_time, departure_time, acuity_level,
        chief_complaint, status
      )
      VALUES (
        gen_random_uuid(), %s, %s, %s, %s,
        %s, %s
      )
      RETURNING id
    """
    with conn.cursor() as cur:
        cur.execute(sql, (
            admission_id,
            data.get("arrival_time"),
            data.get("departure_time"),
            data.get("acuity_level"),
            data.get("chief_complaint"),
            data.get("status")
        ))
        new_id = cur.fetchone()[0]
        logger.info(f"Inserted ED Visit row with id={new_id}")
    conn.commit()

def find_or_create_admission_for_ed(conn, data: Dict[str, Any]) -> Optional[str]:
    """
    Because ed_visit needs an admission row, but FHIR doesn't always specify that concept,
    we can create a minimal 'patient_admission' row if needed.
    We'll look for existing admission by patient + some timeframe, or just create a new row.
    """
    patient_uuid = data.get("patient_id")
    if not patient_uuid:
        return None

    # 1) Try to see if there's an existing row in patient_admission for this patient that is still active
    sql_check = """
      SELECT id
        FROM fhir.patient_admission
       WHERE patient_id = %s
         AND status IN ('PENDING','ADMITTED')
       ORDER BY admission_time DESC
       LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql_check, (patient_uuid,))
        row = cur.fetchone()
        if row:
            return row[0]

    # 2) Otherwise, create a brand-new admission row
    sql_ins = """
      INSERT INTO fhir.patient_admission(
        id, patient_id, encounter_id, admission_time, admission_type,
        admission_source, status
      )
      VALUES (
        gen_random_uuid(), %s, NULL, %s, 'EMERGENCY',
        'ED', 'ADMITTED'
      )
      RETURNING id
    """
    # We'll guess arrival_time as the admission_time
    admission_time = data.get("arrival_time")
    with conn.cursor() as cur:
        cur.execute(sql_ins, (patient_uuid, admission_time))
        new_id = cur.fetchone()[0]
        logger.info(f"Created new patient_admission row={new_id} for ED scenario.")
    conn.commit()
    return new_id

def upsert_patient_admission(conn, data: Dict[str, Any]) -> None:
    """
    Insert or update row in fhir.patient_admission for an 'inpatient' encounter.
    """
    sql = """
      INSERT INTO fhir.patient_admission(
        id, patient_id, encounter_id, admission_time, discharge_time,
        admission_type, admission_source, status
      )
      VALUES (
        gen_random_uuid(), %s, %s, %s, %s,
        %s, %s, %s
      )
      RETURNING id
    """
    with conn.cursor() as cur:
        cur.execute(sql, (
            data.get("patient_id"),
            data.get("encounter_id"),
            data.get("admission_time"),
            data.get("discharge_time"),
            data.get("admission_type"),
            data.get("admission_source"),
            data.get("status")
        ))
        new_id = cur.fetchone()[0]
        logger.info(f"Inserted patient_admission row id={new_id}")
    conn.commit()

def upsert_icu_monitoring(conn, data: Dict[str, Any]) -> None:
    """
    Insert a row in icu_monitoring referencing an admission.
    """
    # We have "admission_id": None in parse_observation. So we might need logic
    # to locate an existing admission or require an input.
    # For demonstration, let's skip that and only insert if we can guess an admission.
    if not data.get("admission_id"):
        logger.warning("Skipping icu_monitoring insert because admission_id is missing.")
        return

    sql = """
      INSERT INTO fhir.icu_monitoring(
        id, admission_id, monitoring_time, vital_signs,
        fhir_observation_id
      )
      VALUES (
        gen_random_uuid(), %s, %s, %s::jsonb, %s
      )
      RETURNING id
    """
    with conn.cursor() as cur:
        cur.execute(sql, (
            data["admission_id"],
            data["monitoring_time"],
            json.dumps(data["vital_signs"]),  # store as JSONB
            data["fhir_observation_id"]
        ))
        new_id = cur.fetchone()[0]
        logger.info(f"Inserted icu_monitoring row id={new_id}")
    conn.commit()

# -----------------------------------------------------------------------------
# 6. Master function to store domain data
# -----------------------------------------------------------------------------
def store_parsed_data(conn, parsed_data: Dict[str, Any]) -> None:
    """
    Based on the "domain_table" key, call the appropriate upsert function.
    """
    if not parsed_data:
        return

    domain_table = parsed_data.get("domain_table")
    if not domain_table:
        return  # No recognized table

    if domain_table == "ed_visit":
        upsert_ed_visit(conn, parsed_data)
    elif domain_table == "patient_admission":
        upsert_patient_admission(conn, parsed_data)
    elif domain_table == "icu_monitoring":
        upsert_icu_monitoring(conn, parsed_data)
    else:
        logger.info(f"No store logic for domain_table={domain_table}. Skipping.")


# -----------------------------------------------------------------------------
# 7. Main pipeline
# -----------------------------------------------------------------------------
def main():
    logger.info("Starting comprehensive FHIR parser for custom fhir schema.")

    # Connect to Postgres
    conn = psycopg2.connect(**DB_CONFIG)

    try:
        with conn.cursor() as cur:
            # Fetch resources from fhir.fhir_resource
            # We can limit by resource_type or do them all
            sql = """
                SELECT id, resource_type, resource_json
                  FROM fhir.fhir_resource
                 ORDER BY last_updated DESC
            """
            cur.execute(sql)
            rows = cur.fetchall()
            logger.info(f"Fetched {len(rows)} resources from fhir.fhir_resource.")

        for row in rows:
            fhir_id, resource_type, resource_json = row
            # resource_json is a Python dict if psycopg2 is set up with jsonb -> Python,
            # but sometimes it might be a string. If it's a string, do: resource_json = json.loads(resource_json)

            # 1) Parse the resource
            parsed_output = parse_resource(resource_type, resource_json)
            if not parsed_output:
                continue

            # 2) Store the parsed data in the domain table(s)
            store_parsed_data(conn, parsed_output)

    except Exception as e:
        logger.error(f"Error in main parser: {e}", exc_info=True)
    finally:
        conn.close()
        logger.info("Parser completed.")


if __name__ == "__main__":
    main()

