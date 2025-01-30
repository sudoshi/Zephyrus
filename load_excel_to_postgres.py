import os
import pandas as pd
import numpy as np
from sqlalchemy import create_engine, text, inspect
from sqlalchemy.exc import SQLAlchemyError
import logging
import re
from typing import List, Dict, Any, Optional, Tuple
from enum import Enum
from dataclasses import dataclass
from datetime import datetime
from tqdm import tqdm
import questionary

@dataclass
class TargetTable:
    name: str
    schema: Dict[str, str]  # column_name -> postgres_type
    description: str

# Standard target table configurations
TARGET_TABLES = {
    'or_cases': TargetTable(
        name='or_cases',
        schema={
            'case_id': 'TEXT',
            'case_date': 'DATE',
            'procedure': 'TEXT',
            'surgeon': 'TEXT',
            'duration': 'INTEGER',
            'room': 'TEXT'
        },
        description='Operating room case information'
    ),
    'or_schedules': TargetTable(
        name='or_schedules',
        schema={
            'schedule_id': 'TEXT',
            'schedule_date': 'DATE',
            'room': 'TEXT',
            'start_time': 'TIME',
            'end_time': 'TIME',
            'case_id': 'TEXT'
        },
        description='Operating room scheduling data'
    ),
    'block_templates': TargetTable(
        name='block_templates',
        schema={
            'template_id': 'TEXT',
            'surgeon': 'TEXT',
            'day_of_week': 'TEXT',
            'room': 'TEXT',
            'start_time': 'TIME',
            'end_time': 'TIME'
        },
        description='Block schedule templates'
    ),
    'block_schedules': TargetTable(
        name='block_schedules',
        schema={
            'block_id': 'TEXT',
            'schedule_date': 'DATE',
            'surgeon': 'TEXT',
            'room': 'TEXT',
            'start_time': 'TIME',
            'end_time': 'TIME'
        },
        description='Actual block schedule assignments'
    )
}

class TableAction(Enum):
    CREATE_NEW = "Create new table"
    DROP_RECREATE = "Drop and recreate table"
    TRUNCATE = "Truncate existing table"
    APPEND = "Append to existing table"

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)

# Database configuration
DB_HOST = os.getenv('DB_HOST', 'localhost')
DB_PORT = os.getenv('DB_PORT', '5432')
DB_NAME = os.getenv('DB_NAME', 'OAP')
DB_USER = os.getenv('DB_USER', 'postgres')
DB_PASSWORD = os.getenv('DB_PASSWORD', 'acumenus')
DB_SCHEMA = os.getenv('DB_SCHEMA', 'raw')

def clean_table_name(filename: str) -> str:
    """Convert Excel filename to valid PostgreSQL table name."""
    # Remove file extension
    name = os.path.splitext(filename)[0]
    # Replace spaces and special characters with underscores
    name = re.sub(r'[^a-zA-Z0-9]', '_', name)
    # Convert to lowercase
    name = name.lower()
    # Remove multiple underscores
    name = re.sub(r'_+', '_', name)
    # Remove leading/trailing underscores
    name = name.strip('_')
    return name

def get_postgres_type(dtype: str) -> str:
    """Map pandas data types to PostgreSQL data types."""
    dtype = str(dtype)
    if 'datetime' in dtype:
        return 'TIMESTAMP'
    elif 'date' in dtype:
        return 'DATE'
    elif 'float' in dtype:
        return 'DOUBLE PRECISION'
    elif 'int' in dtype:
        return 'INTEGER'
    elif 'bool' in dtype:
        return 'BOOLEAN'
    else:
        return 'TEXT'

def validate_excel_file(file_path: str) -> Tuple[bool, str]:
    """Validate Excel file before processing."""
    try:
        file_size = os.path.getsize(file_path)
        if file_size == 0:
            return False, "File is empty"
        
        # Try reading first few rows to validate format
        pd.read_excel(file_path, nrows=5)
        return True, "File is valid"
    except Exception as e:
        return False, f"File validation failed: {str(e)}"

def backup_table(engine: Any, schema: str, table_name: str) -> bool:
    """Create a backup of the existing table."""
    try:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        backup_table = f"{table_name}_backup_{timestamp}"
        
        with engine.connect() as conn:
            with conn.begin():
                conn.execute(text(
                    f"CREATE TABLE {schema}.{backup_table} AS "
                    f"SELECT * FROM {schema}.{table_name}"
                ))
                logging.info(f"Created backup table: {schema}.{backup_table}")
        return True
    except Exception as e:
        logging.error(f"Backup failed: {str(e)}")
        return False

def handle_existing_table(
    engine: Any,
    schema: str,
    table_name: str,
    action: TableAction
) -> bool:
    """Handle existing table based on user selection."""
    try:
        with engine.connect() as conn:
            inspector = inspect(engine)
            table_exists = table_name in inspector.get_table_names(schema=schema)
            
            if not table_exists and action != TableAction.CREATE_NEW:
                logging.warning("Table doesn't exist. Will create new table.")
                return True
            
            with conn.begin():
                if action == TableAction.DROP_RECREATE:
                    conn.execute(text(f"DROP TABLE IF EXISTS {schema}.{table_name}"))
                elif action == TableAction.TRUNCATE:
                    conn.execute(text(f"TRUNCATE TABLE {schema}.{table_name}"))
                elif action == TableAction.CREATE_NEW and table_exists:
                    return False
            return True
    except Exception as e:
        logging.error(f"Error handling existing table: {str(e)}")
        return False

def get_target_table_mapping() -> Dict[str, List[str]]:
    """Interactive workflow to map source files to target tables."""
    print("\nAvailable target tables:")
    for name, table in TARGET_TABLES.items():
        print(f"\n{name}: {table.description}")
        print("Expected columns:")
        for col, dtype in table.schema.items():
            print(f"  - {col}: {dtype}")

    excel_files = [f for f in os.listdir() if f.endswith(('.xlsx', '.xls'))]
    if not excel_files:
        logging.error("No Excel files found in current directory")
        return {}

    mapping = {}
    print("\nAvailable source files:")
    for file in excel_files:
        print(f"- {file}")

    for file in excel_files:
        # Ask if user wants to load this file
        if not questionary.confirm(f"\nDo you want to load {file}?").ask():
            continue
        
        # Show preview of file contents
        preview = pd.read_excel(file, nrows=5)
        print(f"\nPreview of {file}:")
        print(preview.head())
        print(f"\nColumns: {list(preview.columns)}")
        
        # Let user select target table
        target = questionary.select(
            "Select target table for this file:",
            choices=list(TARGET_TABLES.keys()) + ["Skip this file"]
        ).ask()
        
        if target != "Skip this file":
            if target not in mapping:
                mapping[target] = []
            mapping[target].append(file)

    return mapping

def validate_and_map_columns(
    df: pd.DataFrame,
    target: TargetTable
) -> Tuple[pd.DataFrame, bool]:
    """Validate and map source columns to target schema."""
    source_cols = set(df.columns)
    target_cols = set(target.schema.keys())
    
    # Find missing required columns
    missing = target_cols - source_cols
    if missing:
        print(f"\nMissing required columns: {missing}")
        if not questionary.confirm("Continue with mapping available columns?").ask():
            return df, False
    
    # Interactive column mapping
    col_mapping = {}
    for target_col in target_cols:
        if target_col in source_cols:
            col_mapping[target_col] = target_col
        else:
            source_col = questionary.select(
                f"Map source column to {target_col}:",
                choices=list(source_cols) + ["Skip this column"]
            ).ask()
            if source_col != "Skip this column":
                col_mapping[target_col] = source_col
    
    # Apply mapping and data type conversions
    mapped_df = pd.DataFrame()
    for target_col, pg_type in target.schema.items():
        if target_col in col_mapping:
            source_col = col_mapping[target_col]
            mapped_df[target_col] = df[source_col]
            # Convert data types
            try:
                if 'DATE' in pg_type:
                    mapped_df[target_col] = pd.to_datetime(mapped_df[target_col]).dt.date
                elif 'TIME' in pg_type:
                    mapped_df[target_col] = pd.to_datetime(mapped_df[target_col]).dt.time
                elif 'INTEGER' in pg_type:
                    mapped_df[target_col] = pd.to_numeric(mapped_df[target_col], errors='coerce').astype('Int64')
                elif 'NUMERIC' in pg_type:
                    mapped_df[target_col] = pd.to_numeric(mapped_df[target_col], errors='coerce')
            except Exception as e:
                logging.warning(f"Could not convert {target_col} to {pg_type}: {e}")

    return mapped_df, True

def process_excel_file(
    file_path: str,
    target_table: str,
    engine: Any,
    schema: str
) -> Optional[Dict[str, int]]:
    """Process a single Excel file and load it into PostgreSQL.
    
    Returns:
        Optional[Dict[str, int]]: Dictionary with source and target row counts if successful,
                                  None if failed
    """
    try:
        # Validate file
        is_valid, validation_msg = validate_excel_file(file_path)
        if not is_valid:
            logging.error(validation_msg)
            return None

        logging.info(f"Reading file: {file_path}")
        # Read Excel file with progress bar
        df = pd.read_excel(file_path)
        source_rows = len(df)
        logging.info(f"Source file has {source_rows:,} rows")
        
        # Clean column names
        df.columns = [
            re.sub(r'[^a-zA-Z0-9]', '_', col.lower()).strip('_') 
            for col in df.columns
        ]
        
        # Map and validate data against target schema
        mapped_df, is_valid = validate_and_map_columns(df, TARGET_TABLES[target_table])
        if not is_valid:
            logging.error("Column mapping validation failed")
            return None
        
        table_name = TARGET_TABLES[target_table].name
        
        # Check if table exists and get user preference
        inspector = inspect(engine)
        table_exists = table_name in inspector.get_table_names(schema=schema)

        if table_exists:
            action = questionary.select(
                "Table already exists. What would you like to do?",
                choices=[action.value for action in TableAction]
            ).ask()
            action = TableAction(action)
            
            # Ask if backup is needed
            if questionary.confirm("Would you like to backup the existing table?").ask():
                if not backup_table(engine, schema, table_name):
                    if not questionary.confirm("Backup failed. Continue anyway?").ask():
                        return None
            
            if not handle_existing_table(engine, schema, table_name, action):
                logging.error("Failed to handle existing table")
                return None

        # Create or modify table (the CREATE itself happens automatically with to_sql if table doesn't exist)
        with engine.connect() as conn:
            with conn.begin():
                # Use tqdm to show progress during data load
                total_chunks = len(mapped_df) // 1000 + (1 if len(mapped_df) % 1000 > 0 else 0)
                with tqdm(total=total_chunks, desc="Loading data") as pbar:
                    mapped_df.to_sql(
                        table_name,
                        conn,
                        schema=schema,
                        if_exists='append',
                        index=False,
                        method='multi',
                        chunksize=1000
                    )
                    pbar.update(total_chunks)

                # Validate row count
                result = conn.execute(text(f"SELECT COUNT(*) FROM {schema}.{table_name}"))
                target_rows = result.scalar()

                if source_rows != target_rows:
                    raise ValueError(
                        f"Row count mismatch! Source: {source_rows:,}, Target: {target_rows:,}"
                    )

        logging.info(f"Successfully loaded {target_rows:,} rows into {schema}.{table_name}")
        return {"source_rows": source_rows, "target_rows": target_rows}

    except Exception as e:
        logging.error(f"Error processing {file_path}: {str(e)}")
        return None

def main():
    """Main function to process Excel files into standardized tables."""
    # Create database connection
    connection_string = f"postgresql://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}"
    
    try:
        engine = create_engine(connection_string)
        
        # Create schema if it doesn't exist
        with engine.connect() as conn:
            conn.execute(text(f"CREATE SCHEMA IF NOT EXISTS {DB_SCHEMA}"))
        
        # Get interactive mapping of source files to target tables
        file_mapping = get_target_table_mapping()
        if not file_mapping:
            logging.error("No files mapped to target tables")
            return
        
        success_count = 0
        results = []
        total_files = sum(len(files) for files in file_mapping.values())
        
        # Process files with progress bar
        with tqdm(total=total_files, desc="Processing files") as pbar:
            for target_table, files in file_mapping.items():
                for file in files:
                    pbar.set_description(f"Processing {file}")
                    if os.path.exists(file):
                        result = process_excel_file(file, target_table, engine, DB_SCHEMA)
                        if result:
                            success_count += 1
                            results.append((file, result))
                    else:
                        logging.error(f"File not found: {file}")
                    pbar.update(1)
        
        # Print summary
        logging.info("\nProcessing Summary:")
        logging.info(f"Successfully processed {success_count} out of {total_files} files")
        for file, counts in results:
            logging.info(f"\n{file}:")
            logging.info(f"  Source rows: {counts['source_rows']:,}")
            logging.info(f"  Target rows: {counts['target_rows']:,}")

    except SQLAlchemyError as e:
        logging.error(f"Database connection error: {str(e)}")
    except Exception as e:
        logging.error(f"Unexpected error: {str(e)}")
        # Print partial summary if an unexpected error occurs
        logging.info("\nProcessing Summary (Partial):")
        logging.info(f"Successfully processed {success_count} out of {total_files} files")
        for file, counts in results:
            logging.info(f"\n{file}:")
            logging.info(f"  Source rows: {counts['source_rows']:,}")
            logging.info(f"  Target rows: {counts['target_rows']:,}")

if __name__ == "__main__":
    main()
