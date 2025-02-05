#!/usr/bin/env python3

import os
import random
import psycopg2
from psycopg2.extras import execute_values
from datetime import datetime, timedelta

###############################################################################
# CONFIGURATION
###############################################################################
DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = os.getenv("DB_PORT", "5432")
DB_NAME = os.getenv("DB_NAME", "OAP")
DB_USER = os.getenv("DB_USER", "postgres")
DB_PASS = os.getenv("DB_PASS", "acumenus")

# How many new admissions do we want to add each night?
NEW_ADMISSIONS_PER_DAY = 15

# Probability that an admitted patient gets discharged in this nightly run
DISCHARGE_PROBABILITY = 0.25

# Probability that an admitted patient gets transferred
TRANSFER_PROBABILITY = 0.10

# Probability that a new order or event is created for an active admission
ORDER_EVENT_PROBABILITY = 0.20


###############################################################################
# HELPER FUNCTIONS
###############################################################################

def get_connection():
    """
    Create and return a psycopg2 connection to the database.
    """
    return psycopg2.connect(
        host=DB_HOST,
        port=DB_PORT,
        dbname=DB_NAME,
        user=DB_USER,
        password=DB_PASS
    )


def random_datetime_within(days_back=1):
    """
    Returns a random datetime in the past X days, leaning toward "now".
    For example, if days_back=1, we get something between 24 hours ago and now.
    """
    now = datetime.now()
    start = now - timedelta(days=days_back)
    # Generate a random datetime between start and now
    delta = (now - start).total_seconds()
    random_offset = random.random() * delta
    return start + timedelta(seconds=random_offset)


def pick_random_patient_ids(conn, limit=10):
    """
    Select random patient IDs from your fhir.patient_admission table
    or from fhir.fhir_resource where resource_type='Patient'.
    Adjust as needed for your schema.
    """
    with conn.cursor() as cur:
        cur.execute("""
            SELECT id
            FROM fhir.fhir_resource
            WHERE resource_type = 'Patient'
            ORDER BY RANDOM()
            LIMIT %s;
        """, (limit,))
        return [row[0] for row in cur.fetchall()]


def pick_random_encounter_id_for_patient(conn, patient_id):
    """
    Picks one random Encounter resource for a given patient.
    Adjust the JSON path if your schema differs.
    """
    with conn.cursor() as cur:
        cur.execute("""
            SELECT id
            FROM fhir.fhir_resource
            WHERE resource_type = 'Encounter'
              AND resource_json->'subject'->>'reference' = %s
            ORDER BY random()
            LIMIT 1;
        """, (f"Patient/{patient_id}",))
        row = cur.fetchone()
        return row[0] if row else None


def pick_random_care_area_id(conn, area_type="INPATIENT_UNIT"):
    """
    Picks a random care_area from fhir.care_area by area_type.
    """
    with conn.cursor() as cur:
        cur.execute("""
            SELECT id
            FROM fhir.care_area
            WHERE area_type = %s
            ORDER BY random()
            LIMIT 1;
        """, (area_type,))
        row = cur.fetchone()
        return row[0] if row else None


###############################################################################
# MAIN LOGIC
###############################################################################

def create_new_admissions(conn):
    """
    Create a batch of new admissions (and related data) for random patients.
    We'll do:
      - Insert into patient_admission
      - Possibly an ED visit if admission_source is ED
      - Possibly create an initial patient_location record
    """
    patient_ids = pick_random_patient_ids(conn, limit=NEW_ADMISSIONS_PER_DAY)
    if not patient_ids:
        return

    care_area_id = pick_random_care_area_id(conn, "INPATIENT_UNIT")
    if not care_area_id:
        return

    # We'll build up lists of rows to insert
    admissions_data = []
    locations_data = []
    ed_visits_data = []

    for p_id in patient_ids:
        encounter_id = pick_random_encounter_id_for_patient(conn, p_id)
        if not encounter_id:
            # Skip if no matching Encounter
            continue

        admission_time = random_datetime_within(days_back=1)
        # 70% elective, 30% emergency
        admission_type = "ELECTIVE" if random.random() < 0.7 else "EMERGENCY"
        # 80% from ED, 20% from somewhere else
        admission_source = "ED" if random.random() < 0.8 else "DIRECT"
        expected_los_days = random.uniform(1, 5)  # 1-5 days

        admissions_data.append((
            p_id,               # patient_id
            encounter_id,       # encounter_id
            admission_time,     # admission_time
            None,               # discharge_time
            admission_type,     # admission_type
            admission_source,   # admission_source
            expected_los_days,  # expected_los_days
            'ADMITTED'          # status
        ))

    with conn.cursor() as cur:
        # Insert new admissions
        sql_admission = """
            INSERT INTO fhir.patient_admission (
                patient_id,
                encounter_id,
                admission_time,
                discharge_time,
                admission_type,
                admission_source,
                expected_los_days,
                status
            ) 
            VALUES %s
            RETURNING id, patient_id, admission_time, discharge_time, admission_type, admission_source, status;
        """
        if admissions_data:
            execute_values(cur, sql_admission, admissions_data)
            new_admissions = cur.fetchall()
        else:
            new_admissions = []

        # For each new admission, create a location entry and possibly ED visit
        for (admission_id,
             patient_id,
             admission_time,
             discharge_time,
             adm_type,
             adm_source,
             status) in new_admissions:

            # Insert initial location
            locations_data.append((
                patient_id,         # patient_id
                admission_id,       # encounter_id or admission_id? Adjust if needed
                care_area_id,       # care_area_id
                'ACTIVE',           # status
                admission_time,     # start_time
                None                # end_time
            ))

            # If source is ED, let's create an ED visit row
            if adm_source == 'ED':
                arrival_time = admission_time
                triage_time = arrival_time + timedelta(minutes=random.randint(5, 30))
                provider_time = arrival_time + timedelta(minutes=random.randint(30, 120))
                disposition_time = arrival_time + timedelta(hours=random.randint(2, 6))
                # 1-5 acuity, weighted
                r = random.random()
                if r < 0.05:
                    acuity = 1
                elif r < 0.15:
                    acuity = 2
                elif r < 0.45:
                    acuity = 3
                elif r < 0.80:
                    acuity = 4
                else:
                    acuity = 5

                chief_complaints = [
                    'Chest pain', 'Shortness of breath', 'Abdominal pain',
                    'Fever', 'Headache', 'Back pain', 'Trauma'
                ]
                complaint = random.choice(chief_complaints)

                # If we know this is going to be an admission, set disposition to 'ADMIT'
                # but some might get discharged from ED
                if status == 'ADMITTED':
                    disposition_type = 'ADMIT'
                else:
                    disposition_type = 'HOME'
                ed_status = 'ACTIVE'  # We'll set to COMPLETED later if they move out

                ed_visits_data.append((
                    admission_id,
                    arrival_time,
                    triage_time,
                    provider_time,
                    disposition_time,
                    None,               # departure_time
                    acuity,
                    complaint,
                    disposition_type,
                    ed_status
                ))

        # Insert location data
        sql_location = """
            INSERT INTO fhir.patient_location (
                patient_id,
                encounter_id,
                care_area_id,
                status,
                start_time,
                end_time
            )
            VALUES %s;
        """
        if locations_data:
            execute_values(cur, sql_location, locations_data)

        # Insert ED visits
        sql_ed_visit = """
            INSERT INTO fhir.ed_visit (
                admission_id,
                arrival_time,
                triage_time,
                provider_time,
                disposition_time,
                departure_time,
                acuity_level,
                chief_complaint,
                disposition_type,
                status
            )
            VALUES %s;
        """
        if ed_visits_data:
            execute_values(cur, sql_ed_visit, ed_visits_data)

    print(f"Created {len(admissions_data)} new admissions.")


def discharge_or_transfer_patients(conn):
    """
    Randomly pick currently ADMITTED patients:
      - Some fraction get discharged
      - Some fraction get transferred to another care area
    """
    with conn.cursor() as cur:
        # Get a list of currently admitted admissions
        cur.execute("""
            SELECT id, patient_id 
            FROM fhir.patient_admission
            WHERE status = 'ADMITTED'
        """)
        admitted = cur.fetchall()

    if not admitted:
        return

    discharges = []
    transfers = []

    for (admission_id, patient_id) in admitted:
        # Decide discharge vs transfer vs keep as-is
        if random.random() < DISCHARGE_PROBABILITY:
            # We'll discharge
            discharge_time = datetime.now()
            discharges.append((discharge_time, 'DISCHARGED', admission_id))

        elif random.random() < TRANSFER_PROBABILITY:
            # We'll transfer: pick a different care area
            transfers.append(admission_id)

    with conn.cursor() as cur:
        # Process discharges
        if discharges:
            cur.executemany("""
                UPDATE fhir.patient_admission
                   SET discharge_time = %s,
                       status = %s
                 WHERE id = %s
            """, discharges)
            # Also close out location
            # (We assume patient_location has one 'ACTIVE' row to set end_time)
            for (discharge_time, _, a_id) in discharges:
                cur.execute("""
                    UPDATE fhir.patient_location
                       SET end_time = %s,
                           status = 'COMPLETED'
                     WHERE encounter_id = %s
                       AND status = 'ACTIVE'
                """, (discharge_time, a_id))

        # Process transfers
        for a_id in transfers:
            # Pick a new area (maybe ED -> INPATIENT_UNIT or something else)
            new_area = pick_random_care_area_id(conn, "INPATIENT_UNIT")
            # End the old location
            cur.execute("""
                UPDATE fhir.patient_location
                   SET end_time = now(),
                       status = 'COMPLETED'
                 WHERE encounter_id = %s
                   AND status = 'ACTIVE';
            """, (a_id,))
            # Insert a new location
            cur.execute("""
                INSERT INTO fhir.patient_location (
                    patient_id,
                    encounter_id,
                    care_area_id,
                    status,
                    start_time,
                    end_time
                )
                SELECT patient_id,
                       id,
                       %s,
                       'ACTIVE',
                       now(),
                       NULL
                FROM fhir.patient_admission
                WHERE id = %s
            """, (new_area, a_id))

    print(f"Discharged {len(discharges)} patients.")
    print(f"Transferred {len(transfers)} patients.")


def create_new_orders_and_events(conn):
    """
    For some fraction of ADMITTED patients, add a random "order" or "clinical_event".
    """
    with conn.cursor() as cur:
        # Get currently admitted admissions
        cur.execute("""
            SELECT id, patient_id
            FROM fhir.patient_admission
            WHERE status = 'ADMITTED'
        """)
        admitted = cur.fetchall()

    if not admitted:
        return

    order_data = []
    event_data = []

    for (admission_id, patient_id) in admitted:
        if random.random() < ORDER_EVENT_PROBABILITY:
            # 50% chance it's a medication order, 50% chance it's a lab order
            if random.random() < 0.5:
                # Medication order
                order_data.append((
                    admission_id,
                    "MEDICATION",
                    datetime.now(),
                    random.choice(["High BP medication", "Pain reliever", "Antibiotic"]),
                    "ACTIVE"
                ))
            else:
                # Lab order
                order_data.append((
                    admission_id,
                    "LAB",
                    datetime.now(),
                    random.choice(["CBC", "CMP", "ABG", "Blood culture"]),
                    "ACTIVE"
                ))

            # 50% chance also create an event
            if random.random() < 0.5:
                event_time = datetime.now()
                severity = random.choice(["LOW", "MEDIUM", "HIGH"])
                event_type = random.choice(["PAIN_ASSESSMENT", "MEDICATION_ISSUE", "FALL_RISK"])
                details = f"Event {event_type} recorded for patient {patient_id}"
                event_data.append((
                    admission_id,
                    event_type,
                    event_time,
                    severity,
                    details,
                    "COMPLETED"
                ))

    with conn.cursor() as cur:
        if order_data:
            sql_insert_order = """
                INSERT INTO fhir.clinical_order (
                    admission_id,
                    order_type,
                    order_time,
                    order_details,
                    status
                )
                VALUES %s
            """
            execute_values(cur, sql_insert_order, order_data)

        if event_data:
            sql_insert_event = """
                INSERT INTO fhir.clinical_event (
                    admission_id,
                    event_type,
                    event_time,
                    severity,
                    event_details,
                    status
                )
                VALUES %s
            """
            # If your schema stores event_details as JSON, adapt accordingly
            # for now we treat it as text or a JSON field.
            execute_values(cur, sql_insert_event, event_data)

    print(f"Created {len(order_data)} orders.")
    print(f"Created {len(event_data)} events.")


def main():
    conn = get_connection()
    try:
        # 1. Create new admissions for the day
        create_new_admissions(conn)

        # 2. Discharge or transfer some patients
        discharge_or_transfer_patients(conn)

        # 3. Create new orders and clinical events
        create_new_orders_and_events(conn)

        # Finally commit all changes
        conn.commit()
    except Exception as e:
        conn.rollback()
        print("ERROR: ", e)
    finally:
        conn.close()


if __name__ == "__main__":
    main()

