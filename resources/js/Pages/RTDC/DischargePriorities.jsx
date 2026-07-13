import React, { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import generateMockDischargeData from '@/utils/generateMockDischargeData';
import { formatDurationHours } from '@/lib/duration';
import { ReadinessVector } from '@/Components/Ancillary';

const DischargePriorities = (props) => {
  const mockData = {
    priority1: props?.priority1 ?? [],
    priority2: props?.priority2 ?? [],
    priority3: props?.priority3 ?? [],
    priority4: props?.priority4 ?? [],
    hospitals: props?.hospitals ?? [],
    services: props?.services ?? [],
    units: props?.units ?? [],
    ...(props && Object.keys(props).length ? {} : generateMockDischargeData()),
  };
  const [selectedHospital, setSelectedHospital] = useState('all');
  const [selectedService, setSelectedService] = useState('all');
  const [selectedUnit, setSelectedUnit] = useState('all');

  // Filter patients based on selected filters
  const filteredPriorityPatients = useMemo(() => {
    const filterPatients = (patients) => {
      return patients.filter(patient => {
        const hospitalMatch = selectedHospital === 'all' || patient.hospital === selectedHospital;
        const serviceMatch = selectedService === 'all' || patient.service === selectedService;
        const unitMatch = selectedUnit === 'all' || patient.unit === selectedUnit;
        return hospitalMatch && serviceMatch && unitMatch;
      });
    };

    return {
      priority1: filterPatients(mockData.priority1),
      priority2: filterPatients(mockData.priority2),
      priority3: filterPatients(mockData.priority3),
      priority4: filterPatients(mockData.priority4)
    };
  }, [mockData, selectedHospital, selectedService, selectedUnit]);

  const FilterSelect = ({ label, value, onChange, options }) => (
    <div className="flex items-center space-x-2">
      <label className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {label}:
      </label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-sm focus:ring-healthcare-primary focus:border-healthcare-primary"
      >
        <option value="all">All {label}s</option>
        {options.map((option) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </select>
    </div>
  );

  const PrioritySection = ({ priority, patients, description, icon, color }) => (
    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-md p-3 border border-healthcare-border dark:border-healthcare-border-dark h-[calc(35vh-2rem)]">
      <div className="flex items-center mb-2">
        <div className={`p-1.5 rounded-lg ${color} mr-2`}>
          <Icon icon={icon} className="w-4 h-4 text-white" />
        </div>
        <div className="flex-1">
          <div className="flex justify-between items-center">
            <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Priority {priority}
            </h3>
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {patients.length} patients
            </span>
          </div>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark truncate">
            {description}
          </p>
        </div>
      </div>
      <div className="h-[calc(35vh-6rem)] overflow-y-auto">
        <div className="space-y-2">
          {patients.map((patient) => (
            <div
              key={patient.id}
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-4 border border-healthcare-border dark:border-healthcare-border-dark hover:shadow-md transition-shadow duration-300"
            >
              <div className="flex justify-between items-start">
                <div>
                  <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {patient.name}
                  </h4>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {patient.age} years • {patient.hospital}
                  </p>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {patient.service} • {patient.unit}
                  </p>
                </div>
                <div className="text-right">
                  <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    LOS: {formatDurationHours(patient.los == null ? null : Number(patient.los) * 24)} / {formatDurationHours(patient.expectedLos == null ? null : Number(patient.expectedLos) * 24)}
                  </p>
                  <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Unit Capacity: {patient.unitCapacity}
                  </p>
                </div>
              </div>
              <div className="mt-2 flex items-center justify-between">
                <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                  {
                    'Rapid': 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark',
                    'Steady': 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info/20 dark:text-healthcare-info-dark',
                    'Slow': 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark'
                  }[patient.improvement]
                }`}>
                  {patient.improvement} Improvement
                </span>
                <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                  {
                    'Low': 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark',
                    'Medium': 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark',
                    'High': 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark'
                  }[patient.risk]
                }`}>
                  {patient.risk} Risk
                </span>
              </div>
              {(Array.isArray(patient.readiness) ? patient.readiness : [patient.imaging, patient.lab].filter(Boolean)).length > 0 ? (
                <div className="mt-3">
                  <ReadinessVector axes={Array.isArray(patient.readiness) ? patient.readiness : [patient.imaging, patient.lab].filter(Boolean)} variant="compact" onDrill={(href) => router.visit(href)} />
                </div>
              ) : null}
            </div>
          ))}
        </div>
      </div>
    </div>
  );

  return (
    <RTDCPageLayout>
      <div className="flex justify-between items-center mb-3">
        <div>
          <h1 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Discharge Priorities
          </h1>
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Prioritized list of patients for potential discharge based on unit capacity and patient progress
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <FilterSelect
            label="Hospital"
            value={selectedHospital}
            onChange={setSelectedHospital}
            options={mockData.hospitals}
          />
          <FilterSelect
            label="Service"
            value={selectedService}
            onChange={setSelectedService}
            options={mockData.services}
          />
          <FilterSelect
            label="Unit"
            value={selectedUnit}
            onChange={setSelectedUnit}
            options={mockData.units}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
        <PrioritySection
          priority={1}
          patients={filteredPriorityPatients.priority1}
          description="Units with demand/capacity mismatch and patients showing readiness"
          icon="heroicons:exclamation-circle"
          color="bg-healthcare-critical dark:bg-healthcare-critical-dark"
        />
        <PrioritySection
          priority={2}
          patients={filteredPriorityPatients.priority2}
          description="Balanced units with patients showing rapid improvement"
          icon="heroicons:arrow-trending-up"
          color="bg-healthcare-success dark:bg-healthcare-success-dark"
        />
        <PrioritySection
          priority={3}
          patients={filteredPriorityPatients.priority3}
          description="Units approaching or above 80% capacity"
          icon="heroicons:chart-bar"
          color="bg-healthcare-warning dark:bg-healthcare-warning-dark"
        />
        <PrioritySection
          priority={4}
          patients={filteredPriorityPatients.priority4}
          description="To be reevaluated after 2 PM"
          icon="heroicons:clock"
          color="bg-healthcare-info dark:bg-healthcare-info-dark"
        />
      </div>
    </RTDCPageLayout>
  );
};

export default DischargePriorities;
