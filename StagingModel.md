# Staging Data Model Summary

## Core Staging Tables

### 1. Block Schedule (stg.BlockSchedule)
- Primary staging table for OR block schedules
- Captures schedule dates, room assignments, and service blocks
- Key fields: schedule_date, room_id, slot_start_time, slot_end_time
- Includes tracking fields for file loads and updates

### 2. OR Case (stg.ORCase)
- Main table for surgical case details
- Tracks all case timing points (preop to PACU)
- Contains links to patient, surgeon, and location
- Includes detailed case attributes and outcomes

### 3. OR Schedule (stg.ORSchedule)
- Holds scheduling information for cases
- Contains projected times and resource requirements
- Links to surgeons and services
- Tracks schedule changes and cancellations

## Support Tables

### 1. Error Tracking
- `stg.DataLoadError`: Captures file loading errors
- `stg.FileTracking`: Monitors file processing status
- `stg.DataQualityException`: Records data quality issues

### 2. ETL Control
- `stg.ETLControl`: Manages ETL process execution
- Tracks process status, timing, and error counts

## Core Procedures

### 1. Data Loading
- `stg.TrackFileProcessing`: Monitors file loads
- `stg.LogDataError`: Records processing errors
- Input validation and error handling included

### 2. Data Quality
- `stg.ValidateBlockSchedule`: Checks block schedule integrity
- `stg.ValidateORCase`: Validates case data
- `stg.RunDataQualityChecks`: Comprehensive DQ analysis
- `stg.ResolveDataQualityException`: Handles DQ resolution

### 3. Metric Calculations
- `stg.UpdateBlockUtilization`: Block usage metrics
- `stg.AnalyzeTurnoverTimes`: Room turnover analysis
- `stg.AnalyzePrimeTimeUtilization`: Prime time usage tracking

### 4. Process Control
- `stg.RunMasterETL`: Coordinates entire ETL process
- `stg.RetryFailedETL`: Handles process recovery

## Key Features

### 1. Data Quality Controls
- Validation of required fields
- Time sequence checks
- Utilization anomaly detection
- Block overlap prevention
- Duration reasonableness checks

### 2. Error Handling
- Comprehensive error logging
- Process retry capabilities
- Data quality exception tracking
- Resolution workflow support

### 3. Performance Features
- Strategic indexing on key fields
- Partitioning support for large tables
- Efficient date-based processing
- Batch processing capabilities

### 4. Monitoring & Reporting
- Process status tracking
- Error and warning counts
- Data quality metrics
- Utilization analytics

## Design Principles

1. **Data Integrity**
   - Foreign key constraints
   - Data validation rules
   - Comprehensive error logging

2. **Performance**
   - Optimized indexes
   - Efficient date handling
   - Batch processing support

3. **Maintainability**
   - Modular procedure design
   - Consistent naming conventions
   - Clear error handling

4. **Flexibility**
   - Configurable parameters
   - Extensible structure
   - Process retry capabilities

## Common Usage Patterns

1. **Daily Processing**
   ```sql
   EXEC stg.RunMasterETL @ProcessDate = '2024-01-29'
   ```

2. **Data Quality Check**
   ```sql
   EXEC stg.RunDataQualityChecks 
       @StartDate = '2024-01-01', 
       @EndDate = '2024-01-31'
   ```

3. **Metric Generation**
   ```sql
   EXEC stg.UpdateBlockUtilization
       @StartDate = '2024-01-01',
       @EndDate = '2024-01-31'
   ```

4. **Error Resolution**
   ```sql
   EXEC stg.ResolveDataQualityException
       @ExceptionId = 123,
       @ResolvedBy = 'John Smith',
       @ResolutionNotes = 'Validated with department'
   ```