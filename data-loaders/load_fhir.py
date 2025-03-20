#!/usr/bin/env python3

import json
import os
import glob
import logging
import multiprocessing as mp
from functools import partial
from itertools import chain
from rich.console import Console
from rich.table import Table
from rich.prompt import Confirm
from tqdm import tqdm
import time
from datetime import datetime
import psycopg2
from psycopg2.extras import RealDictCursor
from psycopg2.pool import SimpleConnectionPool

# Set up logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Initialize Rich console
console = Console()

# Database configuration
# Configuration
DB_CONFIG = {
    'dbname': 'zephyrus',
    'user': os.getenv('DB_USER', 'postgres'),
    'password': os.getenv('DB_PASSWORD', 'acumenus'),
    'host': os.getenv('DB_HOST', 'localhost'),
    'port': os.getenv('DB_PORT', '5432')
}

# Processing configuration
WORKER_COUNT = os.getenv('WORKER_COUNT', mp.cpu_count())
BATCH_SIZE = int(os.getenv('BATCH_SIZE', '1000'))

# Initialize connection pool
pool = None

def init_connection_pool(min_conn=1, max_conn=10):
    """Initialize the database connection pool."""
    global pool
    try:
        pool = SimpleConnectionPool(min_conn, max_conn, **DB_CONFIG)
        logger.info("Database connection pool initialized successfully")
    except psycopg2.Error as e:
        logger.error(f"Failed to initialize connection pool: {e}")
        raise

def get_connection():
    """Get a connection from the pool."""
    return pool.getconn()

def return_connection(conn):
    """Return a connection to the pool."""
    pool.putconn(conn)

def process_bundle(file_path):
    """Process a FHIR bundle file and return list of resources."""
    try:
        with open(file_path, 'r') as f:
            bundle = json.load(f)
            
        resources = []
        if 'entry' in bundle:
            for entry in bundle['entry']:
                if 'resource' in entry:
                    resources.append(entry['resource'])
        
        return resources
    except json.JSONDecodeError as e:
        logger.error(f"Failed to parse JSON file {file_path}: {e}")
        return []
    except Exception as e:
        logger.error(f"Error processing file {file_path}: {e}")
        return []

def insert_resources(conn, resources):
    """Insert a batch of resources into the database."""
    cursor = conn.cursor()
    inserted = 0
    
    try:
        # Prepare batch values
        values = []
        for resource in resources:
            resource_type = resource.get('resourceType')
            if not resource_type:
                logger.warning("Resource missing resourceType, skipping")
                continue
            values.append((
                resource_type,
                json.dumps(resource),
                datetime.utcnow()
            ))
        
        if values:
            # Use executemany for batch insertion
            sql = """
                INSERT INTO fhir.fhir_resource 
                (resource_type, resource_json, last_updated)
                VALUES (%s, %s, %s)
            """
            cursor.executemany(sql, values)
            inserted = len(values)
        
        conn.commit()
        return inserted
        except psycopg2.Error as e:
            conn.rollback()
            logger.error(f"Database error: {e}")
            raise
        finally:
            cursor.close()

def process_file(file_path):
    """Worker function to process a single file."""
    try:
        resources = process_bundle(file_path)
        if not resources:
            return file_path, 0, ("No resources found", None)
        
        conn = get_connection()
        try:
            # Process resources in batches
            total_inserted = 0
            for i in range(0, len(resources), BATCH_SIZE):
                batch = resources[i:i + BATCH_SIZE]
                inserted = insert_resources(conn, batch)
                total_inserted += inserted
            return file_path, total_inserted, (None, None)
        except Exception as e:
            return file_path, 0, (str(e), e)
        finally:
            return_connection(conn)
    except Exception as e:
        return file_path, 0, (str(e), e)

def main():
    """Main function to process all FHIR files."""
    try:
        # Show welcome message
        console.print("\n[bold blue]FHIR Resource Loader[/bold blue]")
        console.print("This script will load FHIR resources from JSON files into the database.\n")
        
        # Get list of JSON files first
        json_files = glob.glob('*.json')
        total_files = len(json_files)
        
        if total_files == 0:
            console.print("[yellow]No JSON files found in the current directory.[/yellow]")
            return
            
        # Show summary before starting
        console.print(f"[green]Found {total_files} JSON files to process[/green]")
        
        # Get user confirmation
        if not Confirm.ask("Do you want to proceed with loading these files?"):
            console.print("[yellow]Operation cancelled by user[/yellow]")
            return
        
        init_connection_pool()
        
        start_time = time.time()
        total_resources = 0
        failed_files = []
        successful_files = 0
        
        # Initialize the process pool
        with mp.Pool(WORKER_COUNT, initializer=init_connection_pool) as pool:
            # Process files in parallel with progress bar
            with tqdm(total=total_files, desc="Processing files", unit="file") as pbar:
                for file_path, inserted, (error, exc) in pool.imap_unordered(process_file, json_files):
                    pbar.update(1)
                    pbar.set_postfix_str(f"File: {file_path}")
                    
                    if error is None:
                        total_resources += inserted
                        successful_files += 1
                        logger.info(f"Inserted {inserted} resources from {file_path}")
                    else:
                        failed_files.append((file_path, error))
                        logger.error(f"Failed to process {file_path}: {error}")
                        if exc:
                            logger.debug(f"Exception details: {exc}")
        
        # Show final summary
        elapsed_time = time.time() - start_time
        
        console.print("\n[bold green]Processing Summary[/bold green]")
        
        table = Table(show_header=True)
        table.add_column("Metric", style="cyan")
        table.add_column("Value", style="green")
        
        table.add_row("Total Files Processed", str(total_files))
        table.add_row("Successful Files", str(successful_files))
        table.add_row("Failed Files", str(len(failed_files)))
        table.add_row("Total Resources Inserted", str(total_resources))
        table.add_row("Processing Time", f"{elapsed_time:.2f} seconds")
        if elapsed_time > 0:
            table.add_row("Average Speed", f"{total_resources / elapsed_time:.2f} resources/second")
        else:
            table.add_row("Average Speed", "N/A (too quick to measure!)")
        
        console.print(table)
        
        if failed_files:
            console.print("\n[bold red]Failed Files:[/bold red]")
            error_table = Table(show_header=True)
            error_table.add_column("File", style="red")
            error_table.add_column("Error", style="yellow")
            for failed_file, error in failed_files:
                error_table.add_row(failed_file, error)
            console.print(error_table)
    
    except Exception as e:
        logger.error(f"An error occurred: {e}")

if __name__ == '__main__':
    main()

