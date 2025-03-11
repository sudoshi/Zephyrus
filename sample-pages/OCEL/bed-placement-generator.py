import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import random
import json

# Set random seed for reproducibility
np.random.seed(42)

def generate_bed_placement_data(num_cases=50):
    # Start timestamp for today
    base_date = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
    
    # Define hospitals with bed counts from the paper
    hospitals = {
        'Virtua Memorial Hospital': 320,
        'Virtua Voorhees Hospital': 398,
        'Virtua Marlton Hospital': 188,
        'Virtua Willingboro Hospital': 143,
        'Virtua Camden Hospital': 125
    }
    
    # Define input nodes (case origins) from the paper
    origins = [
        'Emergency Department (ED)',
        'Operating Room (OR)',
        'Direct Admission',
        'Transfer'
    ]
    
    # Define origin probabilities (ED and OR are more common)
    origin_probs = [0.45, 0.30, 0.15, 0.10]
    
    # Define core process activities in order
    core_activities = [
        'Bed request initiated',
        'Bed assignment decision',
        'Bed allocation',
        'Transportation ordered',
        'Patient in transit',
        'Patient arrived at bed'
    ]
    
    # Define departments
    departments = [
        'Medical/Surgical',
        'ICU',
        'Telemetry',
        'Orthopedics',
        'Oncology',
        'Neurology',
        'Cardiology'
    ]
    
    # Generate data for each case
    all_events = []
    nodes = []
    edges = []
    
    for case_id in range(1, num_cases + 1):
        # Select random hospital
        hospital = random.choice(list(hospitals.keys()))
        
        # Select random origin based on probabilities
        origin = np.random.choice(origins, p=origin_probs)
        
        # Select random department
        department = random.choice(departments)
        
        # Random request time between 00:00 and 23:59
        request_time = base_date + timedelta(
            hours=random.randint(0, 23),
            minutes=random.randint(0, 59)
        )
        
        # Generate core activities with appropriate delays based on origin
        current_time = request_time
        
        # Different timing patterns based on origin
        if origin == 'Emergency Department (ED)':
            # ED cases tend to be more urgent
            delays = [10, 15, 20, 10, 25]  # Minutes between activities
            durations = [5, 10, 5, 8, 20]  # Duration of each activity
        elif origin == 'Operating Room (OR)':
            # OR cases are often scheduled in advance
            delays = [20, 15, 25, 15, 30]
            durations = [8, 12, 10, 10, 25]
        elif origin == 'Direct Admission':
            # Direct admissions have variable timing
            delays = [30, 25, 30, 20, 35]
            durations = [10, 15, 12, 15, 30]
        else:  # Transfer
            # Transfers may take longer to coordinate
            delays = [45, 30, 35, 25, 40]
            durations = [15, 20, 15, 20, 35]
        
        # Add origin as first activity
        all_events.append({
            'case_id': f'B{case_id:03d}',
            'activity': f'Origin: {origin}',
            'timestamp': current_time,
            'hospital': hospital,
            'department': department,
            'resource': f'Staff_{random.randint(1,20):02d}',
            'duration_mins': 0  # Origin is an instantaneous event
        })
        
        # Add core activities with appropriate delays
        for i, activity in enumerate(core_activities):
            if i < len(delays):  # Skip for the last activity
                # Add delay before next activity
                current_time += timedelta(minutes=random.randint(
                    max(1, int(delays[i] * 0.7)),  # Min delay (70% of average)
                    int(delays[i] * 1.3)  # Max delay (130% of average)
                ))
            
            # Duration for this activity
            duration = random.randint(
                max(1, int(durations[i-1] * 0.7) if i > 0 else 5),
                int(durations[i-1] * 1.3) if i > 0 else 15
            ) if i < len(durations) else random.randint(5, 15)
            
            # Add the activity
            all_events.append({
                'case_id': f'B{case_id:03d}',
                'activity': activity,
                'timestamp': current_time,
                'hospital': hospital,
                'department': department,
                'resource': f'Staff_{random.randint(1,20):02d}',
                'duration_mins': duration
            })
    
    # Convert to DataFrame and sort by timestamp
    df = pd.DataFrame(all_events)
    df = df.sort_values(['timestamp', 'case_id'])
    
    # Add some process variants
    # Simulate some urgent cases with shorter intervals
    urgent_cases = random.sample(df['case_id'].unique().tolist(), int(num_cases * 0.15))
    df.loc[df['case_id'].isin(urgent_cases), 'duration_mins'] = \
        df.loc[df['case_id'].isin(urgent_cases), 'duration_mins'].apply(
            lambda x: max(1, int(x * 0.6))  # Reduce duration by 40% but minimum 1 min
        )
    
    # Add some delayed cases
    delayed_cases = random.sample(
        [c for c in df['case_id'].unique() if c not in urgent_cases], 
        int(num_cases * 0.2)
    )
    df.loc[df['case_id'].isin(delayed_cases), 'duration_mins'] = \
        df.loc[df['case_id'].isin(delayed_cases), 'duration_mins'].apply(
            lambda x: int(x * 1.5)  # Increase duration by 50%
        )
    
    # Create nodes and edges for process map visualization
    # First, get unique activities
    unique_activities = df['activity'].unique().tolist()
    
    # Create nodes
    for i, activity in enumerate(unique_activities):
        node_type = 'start' if 'Origin' in activity else ('end' if activity == 'Patient arrived at bed' else 'activity')
        nodes.append({
            'id': f'node_{i}',
            'label': activity.replace('Origin: ', '') if 'Origin' in activity else activity,
            'type': node_type,
            'count': df[df['activity'] == activity].shape[0],
            'avgDuration': round(df[df['activity'] == activity]['duration_mins'].mean(), 1)
        })
    
    # Create edges based on case sequences
    edge_counts = {}
    for case in df['case_id'].unique():
        case_activities = df[df['case_id'] == case].sort_values('timestamp')['activity'].tolist()
        for i in range(len(case_activities) - 1):
            source_idx = unique_activities.index(case_activities[i])
            target_idx = unique_activities.index(case_activities[i + 1])
            edge_key = f'node_{source_idx}-node_{target_idx}'
            
            if edge_key in edge_counts:
                edge_counts[edge_key] += 1
            else:
                edge_counts[edge_key] = 1
    
    # Convert edge counts to edges list
    for edge_key, count in edge_counts.items():
        source, target = edge_key.split('-')
        edges.append({
            'id': f'edge_{len(edges)}',
            'source': source,
            'target': target,
            'count': count,
            'label': f'{count} cases'
        })
    
    # Calculate process statistics
    total_cases = len(df['case_id'].unique())
    avg_request_to_assignment = calculate_avg_duration(df, 'Bed request initiated', 'Bed allocation')
    avg_assignment_to_arrival = calculate_avg_duration(df, 'Bed allocation', 'Patient arrived at bed')
    total_duration = calculate_avg_duration(df, 'Bed request initiated', 'Patient arrived at bed')
    
    # Find bottlenecks (activities with longest average duration)
    activity_durations = df.groupby('activity')['duration_mins'].mean().reset_index()
    activity_durations = activity_durations[~activity_durations['activity'].str.contains('Origin')]
    bottlenecks = activity_durations.nlargest(2, 'duration_mins')['activity'].tolist()
    
    # Calculate statistics
    stats = {
        'totalCases': total_cases,
        'avgRequestToAssignment': f'{avg_request_to_assignment:.1f} mins',
        'avgAssignmentToArrival': f'{avg_assignment_to_arrival:.1f} mins',
        'totalProcessDuration': f'{total_duration:.1f} mins',
        'bottlenecks': bottlenecks,
        'urgentCases': len(urgent_cases),
        'delayedCases': len(delayed_cases),
        'variantCount': len(df.groupby('case_id')['activity'].apply(tuple).unique())
    }
    
    # Create metrics for the process map
    metrics = {
        'totalCases': total_cases,
        'avgDuration': f'{total_duration:.1f}m',
        'bottleneckCount': len(bottlenecks),
        'reworkPercentage': '12%',  # Mock value
        'throughput': f'{int(total_cases/7)}/day',  # Assuming 7 days of data
        'complianceRate': '87%',  # Mock value
        'variantCount': stats['variantCount']
    }
    
    # Create process map data structure
    process_map = {
        'nodes': nodes,
        'edges': edges,
        'metrics': metrics
    }
    
    return df, stats, process_map

def calculate_avg_duration(df, start_activity, end_activity):
    """Calculate average duration between two activities across all cases"""
    durations = []
    
    for case in df['case_id'].unique():
        case_df = df[df['case_id'] == case].sort_values('timestamp')
        
        if start_activity in case_df['activity'].values and end_activity in case_df['activity'].values:
            start_time = case_df[case_df['activity'] == start_activity]['timestamp'].iloc[0]
            end_time = case_df[case_df['activity'] == end_activity]['timestamp'].iloc[0]
            duration = (end_time - start_time).total_seconds() / 60  # Convert to minutes
            durations.append(duration)
    
    return np.mean(durations) if durations else 0

# Generate the data
df, stats, process_map = generate_bed_placement_data(50)

# Print some sample data and statistics
print("\nSample of generated events:")
print(df.head().to_string())

print("\nProcess Statistics:")
for key, value in stats.items():
    print(f"{key}: {value}")

# Save process map data to JSON file for the frontend
with open('bed_placement_process_map.json', 'w') as f:
    json.dump(process_map, f, indent=2, default=str)

# Export to CSV for further analysis
df.to_csv('bed_placement_data.csv', index=False)

print("\nProcess map data saved to bed_placement_process_map.json")
print("Raw data saved to bed_placement_data.csv")
