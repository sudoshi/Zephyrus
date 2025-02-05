import json
import logging
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import List, Optional, Dict, Any, Iterator, Union
import concurrent.futures
import multiprocessing as mp
from multiprocessing import Pool, Manager
from tqdm import tqdm
import queue
from contextlib import contextmanager
import signal
import sys
from functools import partial
import pandas as pd

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class Patient:
    patient_id: str
    birth_date: Optional[str]
    death_date: Optional[str]
    race: Optional[str]
    ethnicity: Optional[str]
    gender: Optional[str]
    birth_sex: Optional[str]
    birth_place: Optional[str]
    marital_status: Optional[str]
    language: Optional[str]

@dataclass
class Encounter:
    encounter_id: str
    patient_id: str
    start_date: Optional[str]
    end_date: Optional[str]
    type: Optional[str]
    encounter_class: Optional[str]
    location: Optional[str]
    provider_id: Optional[str]
    reason_code: Optional[str]
    discharge_disposition: Optional[str]

@dataclass
class Condition:
    condition_id: str
    patient_id: str
    encounter_id: Optional[str]
    code: str
    code_system: str
    description: Optional[str]
    category: Optional[str]
    onset_date: Optional[str]
    abatement_date: Optional[str]
    clinical_status: Optional[str]

@dataclass
class Observation:
    observation_id: str
    patient_id: str
    encounter_id: Optional[str]
    code: str
    code_system: str
    description: Optional[str]
    value: Optional[str]
    unit: Optional[str]
    date: Optional[str]
    category: Optional[str]
    status: Optional[str]

@dataclass
class Medication:
    medication_id: str
    patient_id: str
    encounter_id: Optional[str]
    code: str
    code_system: str
    description: Optional[str]
    start_date: Optional[str]
    end_date: Optional[str]
    dosage: Optional[str]
    route: Optional[str]
    status: Optional[str]

@dataclass
class Procedure:
    procedure_id: str
    patient_id: str
    encounter_id: Optional[str]
    code: str
    code_system: str
    description: Optional[str]
    date: Optional[str]
    status: Optional[str]
    reason_code: Optional[str]

@dataclass
class Immunization:
    immunization_id: str
    patient_id: str
    encounter_id: Optional[str]
    vaccine_code: str
    date: Optional[str]
    status: Optional[str]
    provider_id: Optional[str]

@dataclass
class DiagnosticReport:
    report_id: str
    patient_id: str
    encounter_id: Optional[str]
    code: str
    code_system: str
    description: Optional[str]
    date: Optional[str]
    status: Optional[str]
    result_ids: List[str]

@dataclass
class Claim:
    claim_id: str
    patient_id: str
    encounter_id: Optional[str]
    total_cost: Optional[float]
    coverage_id: Optional[str]
    status: Optional[str]
    type: Optional[str]
    submission_date: Optional[str]

@dataclass
class ExplanationOfBenefit:
    eob_id: str
    claim_id: str
    patient_id: str
    total_cost: Optional[float]
    covered_amount: Optional[float]
    copay_amount: Optional[float]
    insurance_paid: Optional[float]
    outcome: Optional[str]
    adjudication_date: Optional[str]
class ParsingConfig:
    """Configuration class for parsing parameters"""
    def __init__(
        self,
        batch_size: int = 1000,
        file_workers: int = max(1, mp.cpu_count() - 1),
        resource_workers: int = 4,
        chunk_size: int = 100
    ):
        self.batch_size = batch_size
        self.file_workers = file_workers
        self.resource_workers = resource_workers
        self.chunk_size = chunk_size

class FHIRParser:
    def __init__(
        self, 
        input_directory: str, 
        output_directory: str,
        config: Optional[ParsingConfig] = None
    ):
        self.config = config or ParsingConfig()
        self.manager = Manager()
        self.stop_event = self.manager.Event()
        self.error_queue = self.manager.Queue()
        self._setup_signal_handlers()

    def __init__(self, input_directory: str, output_directory: str):
        self.input_directory = Path(input_directory)
        self.output_directory = Path(output_directory)
        self.output_directory.mkdir(parents=True, exist_ok=True)
        
        # Initialize empty DataFrames for each table
        self.patients_df = pd.DataFrame()
        self.encounters_df = pd.DataFrame()
        self.conditions_df = pd.DataFrame()
        self.observations_df = pd.DataFrame()
        self.medications_df = pd.DataFrame()
        self.procedures_df = pd.DataFrame()
        self.immunizations_df = pd.DataFrame()
        self.diagnostic_reports_df = pd.DataFrame()
        self.claims_df = pd.DataFrame()
        self.explanations_of_benefit_df = pd.DataFrame()
        
        # Track records processed for batch operations
        self.record_counts = {
            'Patient': 0,
            'Encounter': 0,
            'Condition': 0,
            'Observation': 0,
            'MedicationRequest': 0,
            'Procedure': 0,
            'Immunization': 0,
            'DiagnosticReport': 0,
            'Claim': 0,
            'ExplanationOfBenefit': 0
        }

    def parse_patient(self, resource: Dict[str, Any]) -> Patient:
        """Parse FHIR Patient resource into Patient object"""
        try:
            extension_map = {ext.get('url'): ext.get('valueString') 
                        for ext in resource.get('extension', [])}
            
            return Patient(
                patient_id=resource.get('id', ''),
                birth_date=resource.get('birthDate'),
                death_date=resource.get('deceasedDateTime'),
                race=extension_map.get('http://hl7.org/fhir/us/core/StructureDefinition/us-core-race'),
                ethnicity=extension_map.get('http://hl7.org/fhir/us/core/StructureDefinition/us-core-ethnicity'),
                gender=resource.get('gender'),
                birth_sex=extension_map.get('http://hl7.org/fhir/us/core/StructureDefinition/us-core-birthsex'),
                birth_place=resource.get('birthPlace', {}).get('address', {}).get('city'),
                marital_status=resource.get('maritalStatus', {}).get('coding', [{}])[0].get('code'),
                language=next((comm.get('language', {}).get('coding', [{}])[0].get('code')
                            for comm in resource.get('communication', [])), None)
            )
        except Exception as e:
            logger.error(f"Error parsing patient resource: {e}")
            return None

    def parse_encounter(self, resource: Dict[str, Any]) -> Encounter:
        """Parse FHIR Encounter resource into Encounter object"""
        try:
            return Encounter(
                encounter_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                start_date=self.validate_date(resource.get('period', {}).get('start')),
                end_date=self.validate_date(resource.get('period', {}).get('end')),
                type=resource.get('type', [{}])[0].get('coding', [{}])[0].get('code'),
                encounter_class=resource.get('class', {}).get('code'),
                location=resource.get('location', [{}])[0].get('location', {}).get('reference'),
                provider_id=resource.get('serviceProvider', {}).get('reference'),
                reason_code=resource.get('reasonCode', [{}])[0].get('coding', [{}])[0].get('code'),
                discharge_disposition=resource.get('hospitalization', {}).get('dischargeDisposition', {}).get('coding', [{}])[0].get('code')
            )
        except Exception as e:
            logger.error(f"Error parsing encounter resource: {e}")
            return None

    def parse_condition(self, resource: Dict[str, Any]) -> Condition:
        """Parse FHIR Condition resource into Condition object"""
        try:
            coding = resource.get('code', {}).get('coding', [{}])[0]
            return Condition(
                condition_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                code=coding.get('code', ''),
                code_system=coding.get('system', ''),
                description=coding.get('display', ''),
                category=resource.get('category', [{}])[0].get('coding', [{}])[0].get('code'),
                onset_date=self.validate_date(resource.get('onsetDateTime')),
                abatement_date=self.validate_date(resource.get('abatementDateTime')),
                clinical_status=resource.get('clinicalStatus', {}).get('coding', [{}])[0].get('code')
            )
        except Exception as e:
            logger.error(f"Error parsing condition resource: {e}")
            return None

    def parse_observation(self, resource: Dict[str, Any]) -> Observation:
        """Parse FHIR Observation resource into Observation object"""
        try:
            coding = resource.get('code', {}).get('coding', [{}])[0]
            value = None
            unit = None
            
            if 'valueQuantity' in resource:
                value = str(resource['valueQuantity'].get('value'))
                unit = resource['valueQuantity'].get('unit')
            elif 'valueCodeableConcept' in resource:
                value = resource['valueCodeableConcept'].get('coding', [{}])[0].get('code')
            elif 'valueString' in resource:
                value = resource['valueString']
            
            return Observation(
                observation_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                code=coding.get('code', ''),
                code_system=coding.get('system', ''),
                description=coding.get('display', ''),
                value=value,
                unit=unit,
                date=self.validate_date(resource.get('effectiveDateTime')),
                category=resource.get('category', [{}])[0].get('coding', [{}])[0].get('code'),
                status=resource.get('status')
            )
        except Exception as e:
            logger.error(f"Error parsing observation resource: {e}")
            return None

    def parse_medication(self, resource: Dict[str, Any]) -> Medication:
        """Parse FHIR MedicationRequest resource into Medication object"""
        try:
            coding = resource.get('medicationCodeableConcept', {}).get('coding', [{}])[0]
            dosage = resource.get('dosageInstruction', [{}])[0]
            return Medication(
                medication_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                code=coding.get('code', ''),
                code_system=coding.get('system', ''),
                description=coding.get('display', ''),
                start_date=self.validate_date(resource.get('authoredOn')),
                end_date=self.validate_date(resource.get('dispenseRequest', {}).get('validityPeriod', {}).get('end')),
                dosage=str(dosage.get('doseAndRate', [{}])[0].get('doseQuantity', {}).get('value')),
                route=dosage.get('route', {}).get('coding', [{}])[0].get('code'),
                status=resource.get('status')
            )
        except Exception as e:
            logger.error(f"Error parsing medication resource: {e}")
            return None
    def parse_procedure(self, resource: Dict[str, Any]) -> Procedure:
        """Parse FHIR Procedure resource into Procedure object"""
        try:
            coding = resource.get('code', {}).get('coding', [{}])[0]
            return Procedure(
                procedure_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                code=coding.get('code', ''),
                code_system=coding.get('system', ''),
                description=coding.get('display', ''),
                date=self.validate_date(resource.get('performedDateTime')),
                status=resource.get('status'),
                reason_code=resource.get('reasonCode', [{}])[0].get('coding', [{}])[0].get('code')
            )
        except Exception as e:
            logger.error(f"Error parsing procedure resource: {e}")
            return None

    def parse_immunization(self, resource: Dict[str, Any]) -> Immunization:
        """Parse FHIR Immunization resource into Immunization object"""
        try:
            vaccine_coding = resource.get('vaccineCode', {}).get('coding', [{}])[0]
            return Immunization(
                immunization_id=resource.get('id', ''),
                patient_id=resource.get('patient', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                vaccine_code=vaccine_coding.get('code', ''),
                date=self.validate_date(resource.get('occurrenceDateTime')),
                status=resource.get('status'),
                provider_id=resource.get('performer', [{}])[0].get('actor', {}).get('reference')
            )
        except Exception as e:
            logger.error(f"Error parsing immunization resource: {e}")
            return None

    def parse_diagnostic_report(self, resource: Dict[str, Any]) -> DiagnosticReport:
        """Parse FHIR DiagnosticReport resource into DiagnosticReport object"""
        try:
            coding = resource.get('code', {}).get('coding', [{}])[0]
            return DiagnosticReport(
                report_id=resource.get('id', ''),
                patient_id=resource.get('subject', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('encounter', {}).get('reference', '').replace('Encounter/', ''),
                code=coding.get('code', ''),
                code_system=coding.get('system', ''),
                description=coding.get('display', ''),
                date=self.validate_date(resource.get('effectiveDateTime')),
                status=resource.get('status'),
                result_ids=[result['reference'].replace('Observation/', '') 
                        for result in resource.get('result', [])]
            )
        except Exception as e:
            logger.error(f"Error parsing diagnostic report resource: {e}")
            return None

    def parse_claim(self, resource: Dict[str, Any]) -> Claim:
        """Parse FHIR Claim resource into Claim object"""
        try:
            return Claim(
                claim_id=resource.get('id', ''),
                patient_id=resource.get('patient', {}).get('reference', '').replace('Patient/', ''),
                encounter_id=resource.get('item', [{}])[0].get('encounter', [{}])[0].get('reference', '').replace('Encounter/', ''),
                total_cost=float(resource.get('total', {}).get('value', 0)),
                coverage_id=resource.get('insurance', [{}])[0].get('coverage', {}).get('reference'),
                status=resource.get('status'),
                type=resource.get('type', {}).get('coding', [{}])[0].get('code'),
                submission_date=self.validate_date(resource.get('created'))
            )
        except Exception as e:
            logger.error(f"Error parsing claim resource: {e}")
            return None

    def parse_explanation_of_benefit(self, resource: Dict[str, Any]) -> ExplanationOfBenefit:
        """Parse FHIR ExplanationOfBenefit resource into ExplanationOfBenefit object"""
        try:
            return ExplanationOfBenefit(
                eob_id=resource.get('id', ''),
                claim_id=resource.get('claim', {}).get('reference', '').replace('Claim/', ''),
                patient_id=resource.get('patient', {}).get('reference', '').replace('Patient/', ''),
                total_cost=float(resource.get('total', [{}])[0].get('amount', {}).get('value', 0)),
                covered_amount=float(resource.get('benefitBalance', [{}])[0].get('financial', [{}])[0].get('allowedMoney', {}).get('value', 0)),
                copay_amount=float(resource.get('benefitBalance', [{}])[0].get('financial', [{}])[0].get('usedMoney', {}).get('value', 0)),
                insurance_paid=float(resource.get('payment', {}).get('amount', {}).get('value', 0)),
                outcome=resource.get('outcome'),
                adjudication_date=self.validate_date(resource.get('created'))
            )
        except Exception as e:
            logger.error(f"Error parsing explanation of benefit resource: {e}")
            return None

    def validate_date(self, date_str: Optional[str]) -> Optional[str]:
        """Validate and standardize date format"""
        if not date_str:
            return None
        try:
            return datetime.fromisoformat(date_str.replace('Z', '+00:00')).isoformat()
        except ValueError:
            logger.warning(f"Invalid date format: {date_str}")
            return None

    def check_batch_save(self, resource_type: str) -> None:
        """Check if batch size reached and save to disk if necessary"""
        self.record_counts[resource_type] += 1
        if self.record_counts[resource_type] >= self.BATCH_SIZE:
            self.save_tables()
            self.record_counts[resource_type] = 0

    def process_file(self, file_path: Path) -> None:
        """Process a single FHIR Bundle file using parallel resource processing"""
        try:
            with open(file_path, 'r') as f:
                bundle = json.load(f)
            
            entries = bundle.get('entry', [])
            total_entries = len(entries)
            
            if total_entries == 0:
                return
            
            with concurrent.futures.ThreadPoolExecutor(
                max_workers=self.config.resource_workers
            ) as executor:
                with tqdm(
                    total=total_entries,
                    desc=f"Processing {file_path.name}",
                    leave=False
                ) as pbar:
                    futures = []
                    for entry in entries:
                        if self.stop_event.is_set():
                            break
                        
                        future = executor.submit(
                            self.process_resource,
                            entry.get('resource', {})
                        )
                        future.add_done_callback(lambda p: pbar.update(1))
                        futures.append(future)
                    
                    concurrent.futures.wait(futures)
                    
                    for future in futures:
                        try:
                            future.result()  # Check for exceptions
                        except Exception as e:
                            logger.error(f"Error processing resource: {e}")
            
            total_entries = len(bundle.get('entry', []))
            logger.info(f"Processing {total_entries} entries in {file_path}")
            
            def process_resource(self, resource: Dict[str, Any]) -> None:
                """Process a single FHIR resource"""
                try:
                    resource_type = resource.get('resourceType')
                    
                    if self.stop_event.is_set():
                        return
                    if resource_type == 'Patient':
                        patient = self.parse_patient(resource)
                        if patient:
                            self.patients_df = pd.concat([
                                self.patients_df,
                                pd.DataFrame([vars(patient)])
                            ], ignore_index=True)
                            self.check_batch_save('Patient')
                            
                    elif resource_type == 'Encounter':
                        encounter = self.parse_encounter(resource)
                        if encounter:
                            self.encounters_df = pd.concat([
                                self.encounters_df,
                                pd.DataFrame([vars(encounter)])
                            ], ignore_index=True)
                            self.check_batch_save('Encounter')
                            
                    elif resource_type == 'Condition':
                        condition = self.parse_condition(resource)
                        if condition:
                            self.conditions_df = pd.concat([
                                self.conditions_df,
                                pd.DataFrame([vars(condition)])
                            ], ignore_index=True)
                            self.check_batch_save('Condition')
                            
                    elif resource_type == 'Observation':
                        observation = self.parse_observation(resource)
                        if observation:
                            self.observations_df = pd.concat([
                                self.observations_df,
                                pd.DataFrame([vars(observation)])
                            ], ignore_index=True)
                            self.check_batch_save('Observation')
                            
                    elif resource_type == 'MedicationRequest':
                        medication = self.parse_medication(resource)
                        if medication:
                            self.medications_df = pd.concat([
                                self.medications_df,
                                pd.DataFrame([vars(medication)])
                            ], ignore_index=True)
                            self.check_batch_save('MedicationRequest')
                            
                    elif resource_type == 'Procedure':
                        procedure = self.parse_procedure(resource)
                        if procedure:
                            self.procedures_df = pd.concat([
                                self.procedures_df,
                                pd.DataFrame([vars(procedure)])
                            ], ignore_index=True)
                            self.check_batch_save('Procedure')
                            
                    elif resource_type == 'Immunization':
                        immunization = self.parse_immunization(resource)
                        if immunization:
                            self.immunizations_df = pd.concat([
                                self.immunizations_df,
                                pd.DataFrame([vars(immunization)])
                            ], ignore_index=True)
                            self.check_batch_save('Immunization')
                            
                    elif resource_type == 'DiagnosticReport':
                        report = self.parse_diagnostic_report(resource)
                        if report:
                            self.diagnostic_reports_df = pd.concat([
                                self.diagnostic_reports_df,
                                pd.DataFrame([vars(report)])
                            ], ignore_index=True)
                            self.check_batch_save('DiagnosticReport')
                            
                    elif resource_type == 'Claim':
                        claim = self.parse_claim(resource)
                        if claim:
                            self.claims_df = pd.concat([
                                self.claims_df,
                                pd.DataFrame([vars(claim)])
                            ], ignore_index=True)
                            self.check_batch_save('Claim')
                            
                    elif resource_type == 'ExplanationOfBenefit':
                        eob = self.parse_explanation_of_benefit(resource)
                        if eob:
                            self.explanations_of_benefit_df = pd.concat([
                                self.explanations_of_benefit_df,
                                pd.DataFrame([vars(eob)])
                            ], ignore_index=True)
                            self.check_batch_save('ExplanationOfBenefit')
                except Exception as e:
                    logger.error(f"Error processing {resource_type} resource: {e}")
                    continue
                
        except json.JSONDecodeError as e:
            logger.error(f"Invalid JSON in file {file_path}: {e}")
        except Exception as e:
            logger.error(f"Error processing file {file_path}: {e}")

    def _setup_signal_handlers(self) -> None:
        """Set up graceful shutdown handlers"""
        def signal_handler(signum, frame):
            logger.info("Shutting down gracefully...")
            self.stop_event.set()
        
        signal.signal(signal.SIGINT, signal_handler)
        signal.signal(signal.SIGTERM, signal_handler)

    def process_directory(self) -> None:
        """Process all FHIR JSON files in the input directory using parallel processing"""
        files = list(self.input_directory.glob('*.json'))
        total_files = len(files)
        
        logger.info(f"Found {total_files} files to process")
        
        with Pool(processes=self.config.file_workers) as pool:
            try:
                with tqdm(total=total_files, desc="Processing files") as pbar:
                    for _ in pool.imap_unordered(
                        partial(self.process_file_wrapper, pbar=pbar),
                        files,
                        chunksize=self.config.chunk_size
                    ):
                        if not self.error_queue.empty():
                            error = self.error_queue.get()
                            logger.error(f"Critical error encountered: {error}")
                            pool.terminate()
                            break
                        
                        if self.stop_event.is_set():
                            logger.info("Stopping file processing...")
                            pool.terminate()
                            break
            
            except Exception as e:
                logger.error(f"Error in process_directory: {e}")
                pool.terminate()
            finally:
                pool.close()
                pool.join()
                self.save_tables()  # Final save of any remaining data

    def process_file_wrapper(self, file_path: Path, pbar: tqdm) -> None:
        """Wrapper for process_file to handle progress bar updates"""
        try:
            self.process_file(file_path)
        except Exception as e:
            logger.error(f"Error processing file {file_path}: {e}")
            self.error_queue.put(e)
        finally:
            pbar.update(1)

    def save_tables(self) -> None:
        """Save all parsed tables to CSV files"""
        logger.info("Saving tables to CSV files...")
        
        # Save core tables
        self.patients_df.to_csv(self.output_directory / 'patients.csv', index=False)
        self.encounters_df.to_csv(self.output_directory / 'encounters.csv', index=False)
        self.conditions_df.to_csv(self.output_directory / 'conditions.csv', index=False)
        self.observations_df.to_csv(self.output_directory / 'observations.csv', index=False)
        
        # Save clinical tables
        self.medications_df.to_csv(self.output_directory / 'medications.csv', index=False)
        self.procedures_df.to_csv(self.output_directory / 'procedures.csv', index=False)
        self.immunizations_df.to_csv(self.output_directory / 'immunizations.csv', index=False)
        self.diagnostic_reports_df.to_csv(self.output_directory / 'diagnostic_reports.csv', index=False)
        
        # Save administrative tables
        self.claims_df.to_csv(self.output_directory / 'claims.csv', index=False)
        self.explanations_of_benefit_df.to_csv(self.output_directory / 'explanations_of_benefit.csv', index=False)
        
        logger.info("Tables saved successfully")
        
        # Reset DataFrames after saving to free memory
        self.patients_df = pd.DataFrame()
        self.encounters_df = pd.DataFrame()
        self.conditions_df = pd.DataFrame()
        self.observations_df = pd.DataFrame()
        self.medications_df = pd.DataFrame()
        self.procedures_df = pd.DataFrame()
        self.immunizations_df = pd.DataFrame()
        self.diagnostic_reports_df = pd.DataFrame()
        self.claims_df = pd.DataFrame()
        self.explanations_of_benefit_df = pd.DataFrame()

def main():
    parser = FHIRParser(
        input_directory='fhir_data',
        output_directory='analytical_tables'
    )
    
    parser.process_directory()
    parser.save_tables()
    
    logger.info("FHIR parsing completed successfully")

if __name__ == '__main__':
    main()

