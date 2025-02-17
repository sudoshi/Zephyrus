import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import random

def generate_admission_data(num_admissions=28):
    # Set random seed for reproducibility
    np.random.seed(42)
    
    # Start timestamp for today
    base_date = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    
    # Define standard admission process activities
    core_activities = [
        'Patient Arrival',
        'Registration',
        'Initial Triage',
        'Vital Signs',
        'Provider Assessment',
        'Admission Decision',
        'Bed Request',
        'Bed Assignment',
        'Unit Notification',
        'Patient Transport',
        'Unit Arrival'
    ]
    
    # Additional potential activities
    additional_activities = [
        'Lab Work',
        'Imaging',
        'Specialty Consultation',
        'Social Work Assessment',
        'Care Management Review',
        'Insurance Verification',
        'Medication History'
    ]
    
    # Admission types
    admission_types = [
        'Direct Admit',
        'Emergency Department',
        'Transfer',
        'Surgical Admission',
        'Observation'
    ]
    
    # Admission type weights
    admission_weights = [0.15, 0.45, 0.1, 0.2, 0.1]
    
    # Units and their typical capacity status
    units = {
        'Medical': {'capacity': 0.85, 'avg_delay': 20},
        'Surgical': {'capacity': 0.75, 'avg_delay': 15},
        'Telemetry': {'capacity': 0.90, 'avg_delay': 25},
        'ICU': {'capacity': 0.95, 'avg_delay': 30}
    }
    
    all_events = []
    
    for admission_id in range(1, num_admissions + 1):
        # Select admission type
        admission_type = np.random.choice(admission_types, p=admission_weights)
        
        # Determine arrival time distribution based on admission type
        if admission_type == 'Surgical Admission':
            hour = int(np.random.normal(8, 1))  # Cluster around 8 AM
        elif admission_type == 'Emergency Department':
            hour = random.randint(0, 23)  # Any time
        else:
            hour = int(np.random.normal(14, 3))  # Cluster around 2 PM
            
        hour = max(0, min(hour, 23))  # Ensure valid hour
        
        arrival_time = base_date + timedelta(
            hours=hour,
            minutes=random.randint(0, 59)
        )
        
        # Select unit based on admission type
        if admission_type == 'Surgical Admission':
            unit = 'Surgical'
        elif admission_type == 'Emergency Department':
            unit = random.choice(['Medical', 'Telemetry', 'ICU'])
        else:
            unit = random.choice(list(units.keys()))
            
        # Add unit capacity-based delays
        unit_delay = int(np.random.normal(
            units[unit]['avg_delay'] * units[unit]['capacity'],
            10
        ))
        
        # Generate core activities
        current_time = arrival_time
        for activity in core_activities:
            # Base delay for activity
            base_delay = random.randint(10, 30)
            
            # Add type-specific delays
            if activity == 'Bed Assignment':
                base_delay += unit_delay
            elif activity == 'Provider Assessment' and admission_type == 'Emergency Department':
                base_delay += random.randint(15, 45)  # ED often has longer wait times
                
            current_time += timedelta(minutes=base_delay)
            
            all_events.append({
                'case_id': f'A{admission_id:03d}',
                'activity': activity,
                'timestamp': current_time,
                'unit': unit,
                'resource': f'Staff_{random.randint(1,15):02d}',
                'duration_mins': base_delay,
                'admission_type': admission_type
            })
        
        # Add additional activities based on admission type
        num_additional = random.randint(2, 4)
        if admission_type in ['Emergency Department', 'Transfer']:
            num_additional += 1  # More activities for complex admissions
            
        selected_activities = random.sample(additional_activities, num_additional)
        
        for activity in selected_activities:
            # Random time between arrival and unit arrival
            activity_time = arrival_time + timedelta(
                minutes=random.randint(30, int((current_time - arrival_time).total_seconds() / 60))
            )
            
            all_events.append({
                'case_id': f'A{admission_id:03d}',
                'activity': activity,
                'timestamp': activity_time,
                'unit': unit,
                'resource': f'Staff_{random.randint(1,15):02d}',
                'duration_mins': random.randint(15, 45),
                'admission_type': admission_type
            })
    
    # Convert to DataFrame and sort by timestamp
    df = pd.DataFrame(all_events)
    df = df.sort_values(['timestamp', 'case_id'])
    
    # Calculate total admission time for each case
    admission_times = df.groupby('case_id').agg({
        'timestamp': ['min', 'max']
    }).reset_index()
    admission_times.columns = ['case_id', 'start_time', 'end_time']
    admission_times['total_duration_mins'] = (
        (admission_times['end_time'] - admission_times['start_time'])
        .dt.total_seconds() / 60
    ).round()
    
    # Calculate statistics
    stats = {
        'total_admissions': len(df['case_id'].unique()),
        'avg_admission_duration': admission_times['total_duration_mins'].mean(),
        'admission_type_counts': df.groupby('admission_type')['case_id'].nunique().to_dict(),
        'unit_distribution': df[df['activity'] == 'Unit Arrival']['unit'].value_counts().to_dict(),
        'peak_arrival_hour': df[df['activity'] == 'Patient Arrival']['timestamp'].dt.hour.mode()[0],
        'avg_bed_assignment_time': df[df['activity'] == 'Bed Assignment']['duration_mins'].mean()
    }
    
    return df, stats, admission_times

# Generate the data
df, stats, admission_times = generate_admission_data(28)

# Print some sample data and statistics
print("\nSample of generated admission events:")
print(df.head().to_string())

print("\nAdmission Process Statistics:")
for key, value in stats.items():
    print(f"{key}: {value}")

# Export to CSV for process mining
df.to_csv('admission_operations.csv', index=False)