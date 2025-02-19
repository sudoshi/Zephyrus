import React from 'react';
import { BarChart, Bar, LineChart, Line } from 'recharts';
import { Users, Clock, Brain, Shield } from 'lucide-react';
import MetricChart from '../Common/MetricChart';

const StaffAllocation = ({ resourceData, predictions }) => {
  const getShiftCoverage = () => {
    const shifts = ['Morning', 'Afternoon', 'Night'];
    return shifts.map(shift => ({
      shift,
      nurses: Math.round((predictions.resourceUtilization.nextShift.nurses?.assigned || 0) / (predictions.resourceUtilization.nextShift.nurses?.required || 1) * 100),
      physicians: Math.round((predictions.resourceUtilization.nextShift.physicians?.assigned || 0) / (predictions.resourceUtilization.nextShift.physicians?.required || 1) * 100),
      coverage: Math.random() * 30 + 70 // Simulated coverage percentage
    }));
  };

  const getSkillDistribution = () => {
    return [
      { skill: 'Critical Care', level: 85, demand: 90 },
      { skill: 'Emergency', level: 75, demand: 80 },
      { skill: 'General Care', level: 95, demand: 85 },
      { skill: 'Specialized', level: 70, demand: 75 }
    ];
  };

  const getWorkloadBalance = () => {
    return [
      { role: 'Senior Nurse', current: 85, optimal: 75 },
      { role: 'Staff Nurse', current: 90, optimal: 80 },
      { role: 'Resident', current: 70, optimal: 75 },
      { role: 'Attending', current: 80, optimal: 75 }
    ];
  };

  const shiftCoverage = getShiftCoverage();
  const skillDistribution = getSkillDistribution();
  const workloadBalance = getWorkloadBalance();

  return (
    <div className="space-y-6">
      {/* Shift Coverage Analysis */}
      <div className="healthcare-card">
        <div className="flex items-center gap-3 mb-4">
          <div className="rounded-full bg-healthcare-primary/10 p-3">
            <Clock className="h-6 w-6 text-healthcare-primary" />
          </div>
          <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Shift Coverage Analysis
          </h3>
        </div>
        <div className="h-64">
          <MetricChart
            height="64"
            yAxisLabel="Coverage %"
            xAxisDataKey="shift"
          >
            <BarChart data={shiftCoverage}>
              <Bar 
                dataKey="nurses" 
                name="Nurses" 
                fill="var(--healthcare-primary)" 
                radius={[4, 4, 0, 0]}
              />
              <Bar 
                dataKey="physicians" 
                name="Physicians" 
                fill="var(--healthcare-success)"
                radius={[4, 4, 0, 0]}
              />
            </BarChart>
          </MetricChart>
        </div>
      </div>

      {/* Skill Distribution */}
      <div className="grid grid-cols-2 gap-6">
        <div className="healthcare-card">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-full bg-healthcare-success/10 p-3">
              <Brain className="h-6 w-6 text-healthcare-success" />
            </div>
            <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Skill Distribution
            </h3>
          </div>
          <div className="space-y-4">
            {skillDistribution.map((skill, index) => (
              <div key={index} className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {skill.skill}
                  </span>
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {skill.level}% vs {skill.demand}% needed
                  </span>
                </div>
                <div className="relative h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                  <div 
                    className="absolute h-full bg-healthcare-success rounded-full"
                    style={{ width: `${skill.level}%` }}
                  />
                  <div 
                    className="absolute h-full border-r-2 border-healthcare-warning"
                    style={{ left: `${skill.demand}%` }}
                  />
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Workload Balance */}
        <div className="healthcare-card">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-full bg-healthcare-warning/10 p-3">
              <Shield className="h-6 w-6 text-healthcare-warning" />
            </div>
            <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Workload Balance
            </h3>
          </div>
          <div className="space-y-4">
            {workloadBalance.map((role, index) => (
              <div key={index} className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {role.role}
                  </span>
                  <span className={`font-medium ${
                    role.current > role.optimal + 10
                      ? 'text-healthcare-critical'
                      : role.current > role.optimal + 5
                      ? 'text-healthcare-warning'
                      : 'text-healthcare-success'
                  }`}>
                    {role.current}% Load
                  </span>
                </div>
                <div className="relative h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                  <div 
                    className={`h-full rounded-full ${
                      role.current > role.optimal + 10
                        ? 'bg-healthcare-critical'
                        : role.current > role.optimal + 5
                        ? 'bg-healthcare-warning'
                        : 'bg-healthcare-success'
                    }`}
                    style={{ width: `${role.current}%` }}
                  />
                  <div 
                    className="absolute h-full border-r-2 border-healthcare-primary"
                    style={{ left: `${role.optimal}%` }}
                  />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Quick Actions */}
      <div className="grid grid-cols-3 gap-4">
        {[
          { label: 'Adjust Shift Schedule', icon: Clock },
          { label: 'Request Skill Coverage', icon: Brain },
          { label: 'Balance Workload', icon: Shield }
        ].map((action, index) => (
          <button
            key={index}
            className="healthcare-card p-4 flex items-center gap-3 hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark cursor-pointer healthcare-transition"
          >
            <div className="rounded-full bg-healthcare-primary/10 p-2">
              <action.icon className="h-5 w-5 text-healthcare-primary" />
            </div>
            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {action.label}
            </span>
          </button>
        ))}
      </div>
    </div>
  );
};

export default StaffAllocation;
