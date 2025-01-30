import os
import logging
import pandas as pd
from sqlalchemy import create_engine, Table, Column, MetaData, Index
from sqlalchemy.types import Integer, String, DateTime, Boolean, Text
from sqlalchemy.dialects.postgresql import NUMERIC
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('block_data_import.log'),
        logging.StreamHandler()
    ]
)

# Database connection configuration
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'database': os.getenv('DB_NAME', 'OAP'),
    'user': os.getenv('DB_USER', 'postgres'),
    'password': os.getenv('DB_PASSWORD', '')
}

def get_db_engine():
    """Create and return a SQLAlchemy database engine."""
    try:
        connection_string = f"postgresql://{DB_CONFIG['user']}:{DB_CONFIG['password']}@{DB_CONFIG['host']}/{DB_CONFIG['database']}"
        return create_engine(connection_string)
    except Exception as e:
        logging.error(f"Failed to create database engine: {str(e)}")
        raise

def create_tables(engine):
    """Create both block_template and block_schedule tables with appropriate columns and indexes."""
    metadata = MetaData()
    
    # Create block_template table
    """Create the block_template table with appropriate columns and indexes."""
    metadata = MetaData()
    
    block_template = Table('block_template', metadata,
        Column('schedule_date', DateTime),
        Column('slot_start_time', DateTime),
        Column('slot_end_time', DateTime),
        Column('service_c', Integer),
        Column('group_id', Integer),
        Column('time_off_reason_c', Integer),
        Column('deployment_id', Integer),
        Column('responsible_prov_id', Integer),
        Column('room_id', String(50)),
        Column('room_name', String(100)),
        Column('slot_type_nm', String(50)),
        Column('public_slot_yn', String(1)),
        Column('surgeon_id', String(50)),
        Column('block_key', String(100)),
        Column('title', String(200)),
        Column('abbreviation', String(50)),
        Column('comments', Text),
        Column('prov_name', String(100)),
        Column('loc_name', String(100)),
        Column('location_abbr', String(50)),
        Column('pos_type', String(50)),
        Index('idx_block_template_schedule_date', 'schedule_date'),
        Index('idx_block_template_room_id', 'room_id'),
        Index('idx_block_template_surgeon_id', 'surgeon_id'),
        schema='raw'
    )

    try:
        metadata.create_all(engine)
        
        logging.info("Successfully created block_template table and indexes")
    except Exception as e:
        logging.error(f"Failed to create block_template table: {str(e)}")
        raise

    # Create block_schedule table
    block_schedule = Table('block_schedule', metadata,
        Column('snapshot_date', DateTime),
        Column('or_name', String(100)),
        Column('loc_name', String(100)),
        Column('room_id', String(50)),
        Column('start_time', DateTime),
        Column('end_time', DateTime),
        Column('block_key', String(100)),
        Column('loc_id', Integer),
        Column('slot_type', String(50)),
        Column('title', String(200)),
        Column('slot_length', NUMERIC),
        Column('num_of_unique_releases', Integer),
        Column('rel_days_before_surgery_date', Integer),
        Column('first_change', DateTime),
        Column('last_change', DateTime),
        Column('snapshot_number', Integer),
        Column('or_template_audit_id', Integer),
        Index('idx_block_schedule_snapshot_date', 'snapshot_date'),
        Index('idx_block_schedule_room_id', 'room_id'),
        Index('idx_block_schedule_block_key', 'block_key'),
        schema='raw'
    )

    try:
        block_schedule.create(engine, checkfirst=True)
        logging.info("Successfully created block_schedule table and indexes")
    except Exception as e:
        logging.error(f"Failed to create block_schedule table: {str(e)}")
        raise
def merge_block_schedules():
    """Merge the two block schedule Excel files into one dataset."""
    try:
        logging.info("Starting block schedule file merge")
        
        # Read both Excel files
        final_df = pd.read_excel(
            "Block Schedule Extracts Final - v2 DATA.xlsx",
            engine='openpyxl'
        )
        releases_df = pd.read_excel(
            "Block Schedule Extracts with First Releases - v2 DATA.xlsx",
            engine='openpyxl'
        )
        
        # Convert column names to lowercase
        final_df.columns = final_df.columns.str.lower()
        releases_df.columns = releases_df.columns.str.lower()
        
        # Merge dataframes
        merged_df = pd.concat([final_df, releases_df], axis=0, ignore_index=True)
        
        # Convert datetime columns
        date_columns = ['snapshot_date', 'start_time', 'end_time', 'first_change', 'last_change']
        for col in date_columns:
            if col in merged_df.columns:
                merged_df[col] = pd.to_datetime(merged_df[col])
        
        logging.info(f"Successfully merged {len(final_df)} and {len(releases_df)} rows into {len(merged_df)} rows")
        return merged_df
        
    except Exception as e:
        logging.error(f"Failed to merge block schedule files: {str(e)}")
        raise

def import_merged_csv_data(engine):
    """Import data from the merged block schedules CSV file to PostgreSQL."""
    try:
        logging.info("Starting block schedule data import from CSV file")
        
        # Read CSV file
        df = pd.read_csv("merged_block_schedules.csv")
        
        # Convert datetime columns
        date_columns = ['snapshot_date', 'start_time', 'end_time', 'first_change', 'last_change']
        for col in date_columns:
            if col in df.columns:
                df[col] = pd.to_datetime(df[col])
        
        # Convert numeric columns
        numeric_columns = ['slot_length', 'num_of_unique_releases', 'rel_days_before_surgery_date', 'snapshot_number']
        for col in numeric_columns:
            if col in df.columns:
                df[col] = pd.to_numeric(df[col], errors='coerce')
        
        # Import to PostgreSQL
        total_rows = len(df)
        chunk_size = 1000
        
        for i in range(0, total_rows, chunk_size):
            chunk = df[i:i + chunk_size]
            chunk.to_sql(
                'block_schedule',
                engine,
                schema='raw',
                if_exists='append' if i > 0 else 'replace',
                index=False
            )
            logging.info(f"Imported block schedule rows {i} to {min(i + chunk_size, total_rows)}")
        
        logging.info(f"Successfully imported {total_rows} block schedule rows from CSV")
        
    except Exception as e:
        logging.error(f"Failed to import block schedule data from CSV: {str(e)}")
        raise

def import_block_schedule_data(engine, df):
    """Import merged block schedule data to PostgreSQL."""
    try:
        logging.info("Starting block schedule data import")
        
        total_rows = len(df)
        chunk_size = 1000
        
        for i in range(0, total_rows, chunk_size):
            chunk = df[i:i + chunk_size]
            chunk.to_sql(
                'block_schedule',
                engine,
                schema='raw',
                if_exists='append' if i > 0 else 'replace',
                index=False
            )
            logging.info(f"Imported block schedule rows {i} to {min(i + chunk_size, total_rows)}")
        
        logging.info(f"Successfully imported {total_rows} block schedule rows")
        
    except Exception as e:
        logging.error(f"Failed to import block schedule data: {str(e)}")
        raise

def import_template_data(engine):
    """Import data from Excel file to PostgreSQL."""
    try:
        logging.info("Starting data import from Excel file")
        
        # Read Excel file
        df = pd.read_excel(
            "BLOCK Template Extract - v2 Data.xlsx",
            engine='openpyxl'
        )
        
        # Convert column names to lowercase
        df.columns = df.columns.str.lower()
        
        # Convert datetime columns
        date_columns = ['schedule_date', 'slot_start_time', 'slot_end_time']
        for col in date_columns:
            df[col] = pd.to_datetime(df[col])
        
        # Convert room_id to string type
        df['room_id'] = df['room_id'].astype(str)

        # Import to PostgreSQL
        total_rows = len(df)
        chunk_size = 1000

        for i in range(0, total_rows, chunk_size):
            chunk = df[i:i + chunk_size]
            chunk.to_sql(
                'block_template',
                engine,
                schema='raw',
                if_exists='append' if i > 0 else 'replace',
                index=False
            )
            logging.info(f"Imported rows {i} to {min(i + chunk_size, total_rows)}")
        
        logging.info(f"Successfully imported {total_rows} rows")
        
    except Exception as e:
        logging.error(f"Failed to import data: {str(e)}")
        raise

def main():
    """Main execution function."""
    try:
        # Initialize database connection
        engine = get_db_engine()
        
        # Create tables
        create_tables(engine)
        
        # Ask user for import method
        print("\nChoose import method for block schedule data:")
        print("1. Merge Excel files and import")
        print("2. Import from existing merged CSV")
        choice = input("Enter your choice (1 or 2): ")
        
        if choice == "1":
            # Process block schedule files
            merged_schedule_df = merge_block_schedules()
            import_block_schedule_data(engine, merged_schedule_df)
        elif choice == "2":
            # Import from merged CSV
            import_merged_csv_data(engine)
        else:
            raise ValueError("Invalid choice. Please enter 1 or 2.")
        
        # Process template data
        import_template_data(engine)
        
        logging.info("All data imports completed successfully")
    except Exception as e:
        logging.error(f"Import process failed: {str(e)}")
        raise

if __name__ == "__main__":
    main()

