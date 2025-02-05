#!/usr/bin/env python3

import yaml
import logging
import sys
from pathlib import Path
from typing import Dict, Any
from fhir_parser import FHIRParser, ParsingConfig

def setup_logging(config: Dict[str, Any]) -> None:
    """Configure logging based on configuration settings."""
    logging.basicConfig(
        level=getattr(logging, config['logging']['level']),
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
        handlers=[
            logging.FileHandler(config['logging']['file']),
            logging.StreamHandler(sys.stdout)
        ]
    )

def load_config(config_path: str = "config.yaml") -> Dict[str, Any]:
    """Load configuration from YAML file."""
    try:
        with open(config_path, 'r') as f:
            return yaml.safe_load(f)
    except yaml.YAMLError as e:
        print(f"Error parsing configuration file: {e}")
        sys.exit(1)
    except FileNotFoundError:
        print(f"Configuration file not found: {config_path}")
        sys.exit(1)

def create_directories(config: Dict[str, Any]) -> None:
    """Create input and output directories if they don't exist."""
    Path(config['paths']['output_directory']).mkdir(parents=True, exist_ok=True)

def main() -> None:
    try:
        # Load configuration
        config = load_config()
        
        # Setup logging
        setup_logging(config)
        logger = logging.getLogger(__name__)
        
        # Create necessary directories
        create_directories(config)
        
        # Create parser configuration
        parser_config = ParsingConfig(
            batch_size=config['batch']['size'],
            file_workers=config['parallel']['file_workers'],
            resource_workers=config['parallel']['resource_workers'],
            chunk_size=config['batch']['chunk_size']
        )
        
        # Initialize and run parser
        parser = FHIRParser(
            input_directory=config['paths']['input_directory'],
            output_directory=config['paths']['output_directory'],
            config=parser_config
        )
        
        logger.info("Starting FHIR parsing process...")
        parser.process_directory()
        logger.info("FHIR parsing completed successfully")
        
    except KeyboardInterrupt:
        logger.info("Parser execution interrupted by user")
        sys.exit(0)
    except Exception as e:
        logger.error(f"An error occurred during parsing: {e}", exc_info=True)
        sys.exit(1)

if __name__ == "__main__":
    main()

