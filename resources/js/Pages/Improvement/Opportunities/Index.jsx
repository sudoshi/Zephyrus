import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Plus, Search, ArrowRight, TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { improvementOpportunities } from '@/mock-data/improvement/index.js';

const getTrendIcon = (trend) => {
  switch (trend.toLowerCase()) {
    case 'increasing':
      return <TrendingUp className="h-4 w-4 text-red-500" />;
    case 'decreasing':
      return <TrendingDown className="h-4 w-4 text-green-500" />;
    default:
      return <Minus className="h-4 w-4 text-gray-500" />;
  }
};

const getImpactColor = (impact) => {
  switch (impact.toLowerCase()) {
    case 'high':
      return 'text-red-600 bg-red-100 dark:text-red-400 dark:bg-red-900';
    case 'medium':
      return 'text-yellow-600 bg-yellow-100 dark:text-yellow-400 dark:bg-yellow-900';
    case 'low':
      return 'text-green-600 bg-green-100 dark:text-green-400 dark:bg-green-900';
    default:
      return 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-900';
  }
};

const getStatusColor = (status) => {
  switch (status.toLowerCase()) {
    case 'new':
      return 'text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900';
    case 'in progress':
      return 'text-yellow-600 bg-yellow-100 dark:text-yellow-400 dark:bg-yellow-900';
    case 'completed':
      return 'text-green-600 bg-green-100 dark:text-green-400 dark:bg-green-900';
    default:
      return 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-900';
  }
};

const OpportunityCard = ({ title, description, impact, source, department, status, metrics }) => (
  <div className="healthcare-card hover:shadow-md transition-shadow">
    <div className="p-6">
      <div className="flex justify-between items-start">
        <div className="space-y-2">
          <h3 className="text-lg font-medium">{title}</h3>
          <p className="text-sm text-gray-600 dark:text-gray-400">{description}</p>
          <div className="flex items-center gap-3">
            <span className={`px-2 py-1 rounded-full text-xs font-medium ${getImpactColor(impact)}`}>
              {impact} Impact
            </span>
            <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(status)}`}>
              {status}
            </span>
            <span className="text-sm text-gray-500">
              {department}
            </span>
          </div>
        </div>
        <Link href="/improvement/pdsa/create">
          <button className="healthcare-button-secondary text-sm flex items-center gap-2">
            Create PDSA
            <ArrowRight className="h-4 w-4" />
          </button>
        </Link>
      </div>

      <div className="mt-4 pt-4 border-t">
        <div className="grid grid-cols-3 gap-4">
          <div>
            <div className="text-sm font-medium mb-1">Current</div>
            <div className="text-lg">{metrics.currentValue}</div>
          </div>
          <div>
            <div className="text-sm font-medium mb-1">Target</div>
            <div className="text-lg">{metrics.target}</div>
          </div>
          <div>
            <div className="text-sm font-medium mb-1">Trend</div>
            <div className="flex items-center gap-1">
              {getTrendIcon(metrics.trend)}
              <span className="text-sm">{metrics.trend}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
);

const Index = ({ auth }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('all');
  const [impactFilter, setImpactFilter] = useState('all');

  const filteredOpportunities = improvementOpportunities
    .filter(opportunity => {
      const matchesSearch = opportunity.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
        opportunity.description.toLowerCase().includes(searchTerm.toLowerCase());
      const matchesDepartment = departmentFilter === 'all' || opportunity.department.toLowerCase() === departmentFilter.toLowerCase();
      const matchesImpact = impactFilter === 'all' || opportunity.impact.toLowerCase() === impactFilter.toLowerCase();
      return matchesSearch && matchesDepartment && matchesImpact;
    });

  const uniqueDepartments = [...new Set(improvementOpportunities.map(o => o.department))];

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Improvement Opportunities" />
      
      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <div className="healthcare-card">
            <div className="border-b p-6">
              <div className="flex justify-between items-center">
                <h2 className="text-2xl font-semibold">Improvement Opportunities</h2>
                <button className="healthcare-button-primary flex items-center gap-2">
                  <Plus className="h-4 w-4" />
                  Add Opportunity
                </button>
              </div>

              <div className="mt-4 flex flex-col sm:flex-row gap-4">
                <div className="flex-1 relative">
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Search opportunities..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="healthcare-input pl-9 w-full"
                  />
                </div>
                <select
                  value={departmentFilter}
                  onChange={(e) => setDepartmentFilter(e.target.value)}
                  className="healthcare-input"
                >
                  <option value="all">All Departments</option>
                  {uniqueDepartments.map(dept => (
                    <option key={dept} value={dept.toLowerCase()}>{dept}</option>
                  ))}
                </select>
                <select
                  value={impactFilter}
                  onChange={(e) => setImpactFilter(e.target.value)}
                  className="healthcare-input"
                >
                  <option value="all">All Impact Levels</option>
                  <option value="high">High Impact</option>
                  <option value="medium">Medium Impact</option>
                  <option value="low">Low Impact</option>
                </select>
              </div>
            </div>

            <div className="p-6">
              <div className="space-y-4">
                {filteredOpportunities.map((opportunity, index) => (
                  <OpportunityCard key={index} {...opportunity} />
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
};

export default Index;
