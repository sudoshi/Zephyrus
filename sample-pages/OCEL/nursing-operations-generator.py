import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import random

# Set random seed for reproducibility
np.random.seed(42)

def generate_patient_data(num_patients=28):
    # Start timestamp for today
    base_date = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    
    # Define possible activities in order
    core_activities = [
        'Admission',
        'Initial Assessment',
        'Vital Signs',
        'Doctor Round',
        'Medication Administration',
        'Nursing Assessment',
        'Care Plan Update'
    ]
    
    # Additional activities that might occur
    additional_activities = [
        'Blood Draw',
        'IV Change',
        'Physical Therapy',
        'Imaging',
        'Specialist Consultation',
        'Pain Assessment',
        'Family Meeting'
    ]
    
    # Common nursing units
    units = ['Medical', 'Surgical', 'Telemetry', 'ICU']
    
    # Generate data for each patient
    all_events = []
    
    for patient_id in range(1, num_patients + 1):
        # Assign random unit
        unit = random.choice(units)
        
        # Random admission time between 00:00 and 23:59
        admission_time = base_date + timedelta(
            hours=random.randint(0, 23),
            minutes=random.randint(0, 59)
        )
        
        # Generate core activities
        current_time = admission_time
        for activity in core_activities:
            # Add some random delay between activities
            current_time += timedelta(minutes=random.randint(15, 45))
            
            # Add the activity
            all_events.append({
                'case_id': f'P{patient_id:03d}',
                'activity': activity,
                'timestamp': current_time,
                'unit': unit,
                'resource': f'Nurse_{random.randint(1,10):02d}',
                'duration_mins': random.randint(10, 30)
            })
        
        # Add some random additional activities
        num_additional = random.randint(2, 5)
        selected_activities = random.sample(additional_activities, num_additional)
        
        for activity in selected_activities:
            # Random time after admission but before end of day
            activity_time = admission_time + timedelta(
                minutes=random.randint(60, 1380)  # Between 1 hour and 23 hours after admission
            )
            
            all_events.append({
                'case_id': f'P{patient_id:03d}',
                'activity': activity,
                'timestamp': activity_time,
                'unit': unit,
                'resource': f'Nurse_{random.randint(1,10):02d}',
                'duration_mins': random.randint(15, 45)
            })
    
    # Convert to DataFrame and sort by timestamp
    df = pd.DataFrame(all_events)
    df = df.sort_values(['timestamp', 'case_id'])
    
    # Add some process variants
    # Simulate some urgent cases with shorter intervals
    urgent_cases = random.sample(df['case_id'].unique().tolist(), 3)
    df.loc[df['case_id'].isin(urgent_cases), 'duration_mins'] = \
        df.loc[df['case_id'].isin(urgent_cases), 'duration_mins'].apply(
            lambda x: max(5, int(x * 0.6))  # Reduce duration by 40% but minimum 5 mins
        )
    
    # Add some delayed cases
    delayed_cases = random.sample(
        [c for c in df['case_id'].unique() if c not in urgent_cases], 
        4
    )
    df.loc[df['case_id'].isin(delayed_cases), 'duration_mins'] = \
        df.loc[df['case_id'].isin(delayed_cases), 'duration_mins'].apply(
            lambda x: int(x * 1.5)  # Increase duration by 50%
        )
    
    # Calculate some statistics
    stats = {
        'total_cases': len(df['case_id'].unique()),
        'total_events': len(df),
        'avg_activities_per_case': len(df) / len(df['case_id'].unique()),
        'avg_duration_mins': df['duration_mins'].mean(),
        'urgent_cases': urgent_cases,
        'delayed_cases': delayed_cases
    }
    
    return df, stats

# Generate the data
df, stats = generate_patient_data(28)

# Print some sample data and statistics
print("\nSample of generated events:")
print(df.head().to_string())

print("\nProcess Statistics:")
for key, value in stats.items():
    print(f"{key}: {value}")

# Convert to format suitable for PM4Py
event_log = df.to_dict('records')

# You could also export to CSV for PM4Py
df.to_csv('nursing_operations.csv', index=False)