import React, { useState } from 'react';
import { 
  AlertDialog, 
  AlertDialogContent, 
  AlertDialogHeader, 
  AlertDialogFooter, 
  AlertDialogTitle, 
  AlertDialogDescription, 
  AlertDialogAction, 
  AlertDialogCancel 
} from '@/Components/ui/alert-dialog';

import { Button } from '@/Components/ui/button';
import { useDarkMode } from '@/hooks/useDarkMode.js';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';

// Icons (using lucide-react)
import { 
  Camera, 
  AlertCircle, 
  Check, 
  X, 
  ChevronDown, 
  Filter, 
  Download, 
  TrendingUp, 
  TrendingDown, 
  Minus, 
  Calendar, 
  Search, 
  RefreshCw, 
  Clock, 
  Users, 
  Activity, 
  BarChart2, 
  PieChart as PieChartIcon, 
  DollarSign, 
  AlertTriangle, 
  Maximize2
} from 'lucide-react';

// Recharts
import {
  LineChart,
  BarChart,
  PieChart,
  Pie,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  Line,
  Bar,
  Cell,
  ResponsiveContainer,
  ScatterChart,
  Scatter,
  ComposedChart,
  Area,
  Radar,
  RadarChart,
  PolarGrid,
  PolarAngleAxis,
  PolarRadiusAxis,
  Treemap,
  ReferenceLine
} from 'recharts';

/* ------------------------------------------------------------------------- */
/* THEME & COMMON STYLES                                                    */
/* ------------------------------------------------------------------------- */

const colors = {
  primary:    'bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600',
  secondary:  'bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600',
  success:    'bg-green-600 hover:bg-green-700 dark:bg-green-500 dark:hover:bg-green-600',
  warning:    'bg-yellow-500 hover:bg-yellow-600 dark:bg-yellow-400 dark:hover:bg-yellow-500',
  danger:     'bg-red-600 hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600',
  info:       'bg-sky-500 hover:bg-sky-600 dark:bg-sky-400 dark:hover:bg-sky-500'
};

/* ------------------------------------------------------------------------- */
/* BASE COMPONENTS                                                           */
/* ------------------------------------------------------------------------- */

// A reusable panel with a title
export const Panel = ({ children, title, className = '' }) => {
  return (
    <div className={`healthcare-card ${className}`}>
      {title && (
        <h2 className="text-xl font-semibold mb-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {title}
        </h2>
      )}
      {children}
    </div>
  );
};

// A reusable modal based on AlertDialog
export const Modal = ({ isOpen, onClose, title, children, size = 'md' }) => {
  const sizes = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl'
  };

  return (
    <AlertDialog open={isOpen} onOpenChange={onClose}>
      <AlertDialogContent className={`${sizes[size]} p-6`}>
        <AlertDialogHeader>
          <AlertDialogTitle className="text-xl font-semibold">
            {title}
          </AlertDialogTitle>
        </AlertDialogHeader>
        <div className="my-4">{children}</div>
        <AlertDialogFooter>
          <AlertDialogCancel className="mr-2">Cancel</AlertDialogCancel>
          <AlertDialogAction>Continue</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
};

// A specialized button component with healthcare color variants
export const HealthcareButton = ({ 
  variant = 'primary',
  size = 'md',
  children,
  className = '',
  ...props 
}) => {
  const sizes = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2',
    lg: 'px-6 py-3 text-lg'
  };

  return (
    <Button
      className={`
        healthcare-button
        ${variant === 'primary' ? 'healthcare-button-primary' : 'healthcare-button-secondary'}
        ${sizes[size]}
        ${className}
      `}
      {...props}
    >
      {children}
    </Button>
  );
};

// A simple dropdown with label and options
export const Dropdown = ({ label, options, value, onChange, className = '' }) => {
  return (
    <div className={`relative ${className}`}>
      {label && (
        <label className="block mb-1 text-sm font-medium text-gray-700 dark:text-gray-300">
          {label}
        </label>
      )}
      <select
        value={value}
        onChange={onChange}
        className="
          w-full px-3 py-2
          bg-white dark:bg-gray-700
          border border-gray-300 dark:border-gray-600
          rounded-md shadow-sm
          text-gray-900 dark:text-gray-100
          focus:outline-none focus:ring-2 focus:ring-blue-500
          appearance-none
        "
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      <div className="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
        <ChevronDown className="w-4 h-4 text-gray-400" />
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* HEALTHCARE-SPECIFIC COMPONENTS                                            */
/* ------------------------------------------------------------------------- */

// Banner showing patient info
export const PatientBanner = ({ 
  patient, 
  alertCount = 0,
  onAlertClick,
  className = '' 
}) => {
  return (
    <div className={`bg-gray-100 dark:bg-gray-700 p-4 rounded-lg ${className}`}>
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center space-x-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {patient.name} • {patient.mrn}
            </h3>
            {alertCount > 0 && (
              <button
                onClick={onAlertClick}
                className="flex items-center text-red-600 hover:text-red-700"
              >
                <AlertCircle className="w-5 h-5 mr-1" />
                {alertCount} Alert{alertCount !== 1 ? 's' : ''}
              </button>
            )}
          </div>
          <div className="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {patient.age} • {patient.gender} • DOB: {patient.dob}
          </div>
        </div>
        <div className="text-right text-sm text-gray-800 dark:text-gray-200">
          <div>MRN: {patient.mrn}</div>
          <div>Location: {patient.location}</div>
        </div>
      </div>
    </div>
  );
};

// Display for vital signs
export const VitalSigns = ({ vitals, className = '' }) => {
  return (
    <div className={`grid grid-cols-2 md:grid-cols-4 gap-4 ${className}`}>
      {vitals.map((vital) => (
        <div key={vital.name} className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
          <div className="text-sm text-gray-600 dark:text-gray-400">{vital.name}</div>
          <div className="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
            {vital.value}
            <span className="text-sm ml-1">{vital.unit}</span>
          </div>
          <div
            className={`text-sm mt-1 ${
              vital.status === 'normal' ? 'text-green-600 dark:text-green-400' :
              vital.status === 'high' ? 'text-red-600 dark:text-red-400' :
              vital.status === 'low' ? 'text-blue-600 dark:text-blue-400' :
              'text-gray-600 dark:text-gray-400'
            }`}
          >
            {vital.status.charAt(0).toUpperCase() + vital.status.slice(1)}
          </div>
        </div>
      ))}
    </div>
  );
};

// Simple clinical note
export const ClinicalNote = ({ 
  title, 
  content, 
  author, 
  timestamp, 
  className = '' 
}) => {
  return (
    <div className={`border-l-4 border-blue-500 pl-4 ${className}`}>
      <h4 className="font-semibold text-lg text-gray-900 dark:text-gray-100">{title}</h4>
      <div className="mt-2 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
        {content}
      </div>
      <div className="mt-2 text-sm text-gray-600 dark:text-gray-400">
        {author} • {timestamp}
      </div>
    </div>
  );
};

// Order Entry panel for selecting categories and showing selected orders
export const OrderEntry = ({ 
  categories, 
  onOrderSelect, 
  selectedOrders = [], 
  className = '' 
}) => {
  return (
    <div className={`space-y-4 ${className}`}>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {categories.map((category) => (
          <button
            key={category.id}
            onClick={() => onOrderSelect(category)}
            className="p-4 bg-white dark:bg-gray-800 rounded-lg shadow-sm 
                     hover:shadow-md transition-shadow text-left"
          >
            <h4 className="font-semibold text-gray-900 dark:text-gray-100">{category.name}</h4>
            <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
              {category.description}
            </p>
          </button>
        ))}
      </div>
      
      {selectedOrders.length > 0 && (
        <div className="mt-4">
          <h4 className="font-semibold mb-2 text-gray-900 dark:text-gray-100">Selected Orders</h4>
          <div className="space-y-2">
            {selectedOrders.map((order) => (
              <div
                key={order.id}
                className="flex items-center justify-between bg-gray-50 dark:bg-gray-700 p-2 rounded"
              >
                <span className="text-gray-800 dark:text-gray-100">{order.name}</span>
                <button onClick={() => onOrderSelect(order)} className="text-red-600 hover:text-red-700">
                  <X className="w-4 h-4" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

// Timeline of clinical events
export const ClinicalTimeline = ({ events, className = '' }) => {
  return (
    <div className={`space-y-4 ${className}`}>
      {events.map((event, index) => (
        <div key={index} className="flex">
          <div className="flex flex-col items-center">
            <div
              className={`
                w-8 h-8 rounded-full flex items-center justify-center
                ${
                  event.type === 'medication' ? 'bg-blue-100 text-blue-600' :
                  event.type === 'procedure' ? 'bg-purple-100 text-purple-600' :
                  event.type === 'note' ? 'bg-green-100 text-green-600' :
                  'bg-gray-100 text-gray-600'
                }
              `}
            >
              {/* You could place an icon relevant to the event type here */}
            </div>
            {index < events.length - 1 && (
              <div className="w-0.5 h-full bg-gray-200 dark:bg-gray-700" />
            )}
          </div>
          <div className="ml-4 pb-6">
            <div className="text-sm text-gray-600 dark:text-gray-400">
              {event.timestamp}
            </div>
            <div className="font-medium text-gray-900 dark:text-gray-100">{event.title}</div>
            <div className="text-gray-700 dark:text-gray-300">{event.description}</div>
          </div>
        </div>
      ))}
    </div>
  );
};

// Clinical Decision Support Alert
export const CDSAlert = ({ 
  severity = 'info', 
  title, 
  message, 
  recommendations = [],
  onDismiss,
  className = '' 
}) => {
  const severityStyles = {
    critical: 'bg-red-50 border-red-500 text-red-700 dark:bg-red-900 dark:border-red-700 dark:text-red-200',
    warning: 'bg-yellow-50 border-yellow-500 text-yellow-700 dark:bg-yellow-900 dark:border-yellow-600 dark:text-yellow-200',
    info: 'bg-blue-50 border-blue-500 text-blue-700 dark:bg-blue-900 dark:border-blue-700 dark:text-blue-200'
  };

  return (
    <div
      className={`
        border-l-4 p-4 rounded-r
        ${severityStyles[severity] || severityStyles.info}
        ${className}
      `}
    >
      <div className="flex justify-between items-start">
        <div>
          <h4 className="font-semibold">{title}</h4>
          <p className="mt-1">{message}</p>
          {recommendations.length > 0 && (
            <div className="mt-2">
              <h5 className="font-medium">Recommendations:</h5>
              <ul className="list-disc list-inside ml-2 mt-1">
                {recommendations.map((rec, index) => (
                  <li key={index}>{rec}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
        {onDismiss && (
          <button 
            onClick={onDismiss}
            className="text-gray-500 hover:text-gray-700 dark:text-gray-200 dark:hover:text-gray-100"
          >
            <X className="w-5 h-5" />
          </button>
        )}
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* ANALYTICS DASHBOARD COMPONENTS                                            */
/* ------------------------------------------------------------------------- */

// Basic analytics panel with optional actions
export const AnalyticsPanel = ({ 
  title, 
  subtitle,
  children, 
  actions = [], 
  className = '' 
}) => {
  return (
    <div className={`healthcare-panel ${className}`}>
      <div className="p-4 border-b border-healthcare-border dark:border-healthcare-border-dark flex justify-between items-center">
        <div>
          <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {title}
          </h2>
          {subtitle && (
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
              {subtitle}
            </p>
          )}
        </div>
        {actions.length > 0 && (
          <div className="flex space-x-2">
            {actions.map((action, index) => (
              <Button
                key={index}
                onClick={action.onClick}
                variant="ghost"
                className="healthcare-button-secondary inline-flex items-center"
              >
                {action.icon}
                <span className="ml-2">{action.label}</span>
              </Button>
            ))}
          </div>
        )}
      </div>
      <div className="p-4">
        {children}
      </div>
    </div>
  );
};

// KPI or metric card
export const MetricCard = ({ 
  title, 
  value, 
  change, 
  trend = 'neutral',
  icon,
  className = '' 
}) => {
  const trendColors = {
    up: 'text-healthcare-success dark:text-healthcare-success-dark',
    down: 'text-healthcare-critical dark:text-healthcare-critical-dark',
    neutral: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
  };

  return (
    <div className={`healthcare-card ${className}`}>
      <div className="flex items-center justify-between">
        <div className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {title}
        </div>
        {icon && (
          <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {icon}
          </div>
        )}
      </div>
      <div className="mt-2 flex items-baseline">
        <div className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {value}
        </div>
        {change && (
          <span
            className={`
              ml-2 text-sm font-medium 
              ${trendColors[trend]}
            `}
          >
            {change}
          </span>
        )}
      </div>
    </div>
  );
};

// Reusable data table
export const DataTable = ({ 
  columns, 
  data,
  sortable = true,
  filterable = true,
  className = '' 
}) => {
  return (
    <div className={`overflow-x-auto ${className}`}>
      <table className="min-w-full">
        <thead className="bg-gray-50 dark:bg-gray-700">
          <tr>
            {columns.map((column, i) => (
              <th
                key={i}
                className="
                  px-6 py-3 text-left text-xs font-medium
                  text-gray-500 dark:text-gray-300 uppercase tracking-wider
                "
              >
                <div className="flex items-center space-x-1">
                  <span>{column.header}</span>
                  {sortable && (
                    <ChevronDown className="w-4 h-4" />
                  )}
                </div>
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
          {data.map((row, i) => (
            <tr key={i}>
              {columns.map((column, j) => (
                <td
                  key={j}
                  className="
                    px-6 py-4 whitespace-nowrap text-sm
                    text-gray-900 dark:text-gray-100
                  "
                >
                  {row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

// A line or bar chart for analytics
export const AnalyticsChart = ({
  type = 'line',
  data,
  xKey,
  yKeys,
  height = 400,
  className = ''
}) => {
  const chartColors = [
    '#3B82F6', // blue
    '#10B981', // green
    '#F59E0B', // yellow
    '#EF4444', // red
    '#8B5CF6', // purple
  ];

  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        {type === 'line' ? (
          <LineChart data={data}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
            <XAxis dataKey={xKey} className="text-gray-600 dark:text-gray-400" />
            <YAxis className="text-gray-600 dark:text-gray-400" />
            <Tooltip 
              contentStyle={{ 
                backgroundColor: 'rgb(31, 41, 55)', 
                border: 'none',
                borderRadius: '0.375rem',
                color: 'rgb(243, 244, 246)'
              }}
            />
            <Legend />
            {yKeys.map((key, index) => (
              <Line
                key={key}
                type="monotone"
                dataKey={key}
                stroke={chartColors[index % chartColors.length]}
                strokeWidth={2}
                dot={{ r: 4 }}
                activeDot={{ r: 6 }}
              />
            ))}
          </LineChart>
        ) : (
          <BarChart data={data}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
            <XAxis dataKey={xKey} className="text-gray-600 dark:text-gray-400" />
            <YAxis className="text-gray-600 dark:text-gray-400" />
            <Tooltip 
              contentStyle={{ 
                backgroundColor: 'rgb(31, 41, 55)',
                border: 'none',
                borderRadius: '0.375rem',
                color: 'rgb(243, 244, 246)'
              }}
            />
            <Legend />
            {yKeys.map((key, index) => (
              <Bar
                key={key}
                dataKey={key}
                fill={chartColors[index % chartColors.length]}
                radius={[4, 4, 0, 0]}
              />
            ))}
          </BarChart>
        )}
      </ResponsiveContainer>
    </div>
  );
};

// Filter panel to demonstrate filtering controls
export const FilterPanel = ({
  filters,
  onFilterChange,
  className = ''
}) => {
  return (
    <div className={`
      bg-white dark:bg-gray-800 
      rounded-lg shadow-md p-4
      ${className}
    `}>
      <div className="flex items-center mb-4">
        <Filter className="w-5 h-5 text-gray-500 dark:text-gray-400 mr-2" />
        <h3 className="text-sm font-medium text-gray-900 dark:text-gray-100">
          Filters
        </h3>
      </div>
      <div className="space-y-4">
        {filters.map((filter) => (
          <div key={filter.id}>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              {filter.label}
            </label>
            {filter.type === 'select' ? (
              <select
                value={filter.value}
                onChange={(e) => onFilterChange(filter.id, e.target.value)}
                className="
                  w-full rounded-md
                  bg-white dark:bg-gray-700
                  border border-gray-300 dark:border-gray-600
                  text-gray-900 dark:text-gray-100
                  focus:ring-2 focus:ring-blue-500
                "
              >
                {filter.options.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            ) : filter.type === 'dateRange' ? (
              <div className="flex space-x-2">
                <input
                  type="date"
                  value={filter.value.start}
                  onChange={(e) => onFilterChange(filter.id, { 
                    ...filter.value,
                    start: e.target.value 
                  })}
                  className="
                    rounded-md
                    bg-white dark:bg-gray-700
                    border border-gray-300 dark:border-gray-600
                    text-gray-900 dark:text-gray-100
                    focus:ring-2 focus:ring-blue-500
                  "
                />
                <input
                  type="date"
                  value={filter.value.end}
                  onChange={(e) => onFilterChange(filter.id, {
                    ...filter.value,
                    end: e.target.value
                  })}
                  className="
                    rounded-md
                    bg-white dark:bg-gray-700
                    border border-gray-300 dark:border-gray-600
                    text-gray-900 dark:text-gray-100
                    focus:ring-2 focus:ring-blue-500
                  "
                />
              </div>
            ) : null}
          </div>
        ))}
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* ADDITIONAL ANALYTICS COMPONENTS                                           */
/* ------------------------------------------------------------------------- */

// Shows stacked bar of utilized, turnover, unused, blocked
export const BlockUtilizationChart = ({
  data,
  height = 400,
  className = ''
}) => {
  const COLORS = {
    utilized: '#3B82F6',
    turnover: '#10B981',
    unused: '#EF4444',
    blocked: '#6B7280'
  };

  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} stackOffset="expand" barSize={32}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
          <XAxis dataKey="room" className="text-gray-600 dark:text-gray-400" />
          <YAxis
            tickFormatter={(value) => `${(value * 100).toFixed(0)}%`}
            className="text-gray-600 dark:text-gray-400"
          />
          <Tooltip
            formatter={(value) => `${(value * 100).toFixed(1)}%`}
            contentStyle={{ 
              backgroundColor: 'rgb(31, 41, 55)',
              border: 'none',
              borderRadius: '0.375rem',
              color: 'rgb(243, 244, 246)'
            }}
          />
          <Legend />
          <Bar dataKey="utilized" stackId="a" fill={COLORS.utilized} name="Utilized Time" />
          <Bar dataKey="turnover" stackId="a" fill={COLORS.turnover} name="Turnover Time" />
          <Bar dataKey="unused" stackId="a" fill={COLORS.unused} name="Unused Time" />
          <Bar dataKey="blocked" stackId="a" fill={COLORS.blocked} name="Blocked Time" />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
};

// Performance indicator with progress bar
export const PerformanceIndicator = ({
  label,
  value,
  target,
  unit = '%',
  className = ''
}) => {
  const percentage = (value / target) * 100;
  const getColor = () => {
    if (percentage >= 100) return 'text-green-600 dark:text-green-400';
    if (percentage >= 80) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
  };

  const getIcon = () => {
    if (percentage >= 100) return <TrendingUp className="w-5 h-5" />;
    if (percentage >= 80) return <Minus className="w-5 h-5" />;
    return <TrendingDown className="w-5 h-5" />;
  };

  return (
    <div className={`
      bg-white dark:bg-gray-800 
      rounded-lg shadow-md p-4
      ${className}
    `}>
      <div className="flex justify-between items-start">
        <div>
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {label}
          </p>
          <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
            {value}{unit}
          </p>
        </div>
        <div className={`${getColor()} flex items-center`}>
          {getIcon()}
        </div>
      </div>
      <div className="mt-4">
        <div className="relative pt-1">
          <div className="overflow-hidden h-2 text-xs flex rounded bg-gray-200 dark:bg-gray-700">
            <div 
              style={{ width: `${Math.min(percentage, 100)}%` }}
              className={`
                flex flex-col text-center whitespace-nowrap text-white justify-center
                ${getColor().replace('text', 'bg')}
              `}
            />
          </div>
        </div>
        <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">
          Target: {target}{unit}
        </p>
      </div>
    </div>
  );
};

// Timeline for surgical cases
export const SurgicalTimeline = ({
  cases,
  className = ''
}) => {
  return (
    <div className={`space-y-4 ${className}`}>
      {cases.map((surgicalCase, index) => (
        <div key={index} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex justify-between items-start">
            <div>
              <h4 className="font-semibold text-gray-900 dark:text-gray-100">
                {surgicalCase.procedure}
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {surgicalCase.surgeon} • Room {surgicalCase.room}
              </p>
            </div>
            <div className="text-right">
              <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {surgicalCase.time}
              </p>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {surgicalCase.duration} mins
              </p>
            </div>
          </div>
          <div className="mt-4">
            <div className="relative">
              <div className="flex h-2 mb-4">
                <div 
                  style={{ width: `${surgicalCase.progress}%` }}
                  className="bg-blue-500 rounded-l"
                />
                <div 
                  style={{ width: `${100 - surgicalCase.progress}%` }}
                  className="bg-gray-200 dark:bg-gray-700 rounded-r"
                />
              </div>
              <div className="flex justify-between text-xs text-gray-600 dark:text-gray-400">
                <span>Start: {surgicalCase.startTime}</span>
                <span>End: {surgicalCase.endTime}</span>
              </div>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

// Top-level dashboard header with date range & refresh
export const DashboardHeader = ({
  title,
  dateRange,
  onDateRangeChange,
  onRefresh,
  className = ''
}) => {
  return (
    <div className={`healthcare-panel ${className}`}>
      <div className="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {title}
          </h1>
          <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Last updated: {new Date().toLocaleString()}
          </p>
        </div>
        
        <div className="mt-4 md:mt-0 flex space-x-4">
          <div className="flex items-center space-x-2">
            <Calendar className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <select
              value={dateRange}
              onChange={(e) => onDateRangeChange(e.target.value)}
              className="healthcare-input"
            >
              <option value="today">Today</option>
              <option value="week">This Week</option>
              <option value="month">This Month</option>
              <option value="quarter">This Quarter</option>
              <option value="year">This Year</option>
            </select>
          </div>
          
          <Button
            onClick={onRefresh}
            variant="outline"
            className="healthcare-button-secondary flex items-center space-x-2"
          >
            <RefreshCw className="w-4 h-4" />
            <span>Refresh</span>
          </Button>
        </div>
      </div>
    </div>
  );
};

// Simple service line performance card
export const ServiceLineCard = ({
  serviceLine,
  metrics,
  className = ''
}) => {
  const getTrendIcon = (trend) => {
    if (trend === 'up') return <TrendingUp className="w-4 h-4 text-green-500" />;
    if (trend === 'down') return <TrendingDown className="w-4 h-4 text-red-500" />;
    return <Minus className="w-4 h-4 text-gray-500" />;
  };

  return (
    <div className={`
      bg-white dark:bg-gray-800 
      rounded-lg shadow-md p-4
      ${className}
    `}>
      <div className="flex justify-between items-start">
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
          {serviceLine}
        </h3>
      </div>
      <div className="mt-4 grid grid-cols-2 gap-4">
        {metrics.map((metric, index) => (
          <div key={index} className="flex flex-col">
            <div className="flex items-center justify-between">
              <span className="text-sm text-gray-600 dark:text-gray-400">
                {metric.label}
              </span>
              {getTrendIcon(metric.trend)}
            </div>
            <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {metric.value}
            </span>
            <span
              className={`
                text-sm
                ${
                  metric.trend === 'up' ? 'text-green-500' :
                  metric.trend === 'down' ? 'text-red-500' :
                  'text-gray-500'
                }
              `}
            >
              {metric.change}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* FURTHER ANALYTICS (HEATMAP, CAPACITY PLANNING, CASE MIX, ETC.)           */
/* ------------------------------------------------------------------------- */

// Hourly OR utilization heatmap
export const ORHeatmap = ({
  data,
  className = ''
}) => {
  // Helper: color scale for utilization
  const getUtilizationColor = (value) => {
    const intensity = Math.floor((value / 100) * 255);
    return `rgb(${255 - intensity}, ${255 - intensity}, 255)`; 
  };

  const rooms = [...new Set(data.map(d => d.room))].sort();
  const hours = [...new Set(data.map(d => d.hour))].sort();

  return (
    <div className={`overflow-x-auto ${className}`}>
      <div className="min-w-full">
        {/* Hour headers */}
        <div className="flex">
          <div className="w-20 shrink-0" />
          {hours.map(hour => (
            <div 
              key={hour}
              className="w-12 text-center text-xs text-gray-600 dark:text-gray-400"
            >
              {hour}
            </div>
          ))}
        </div>
        {/* Room rows */}
        {rooms.map(room => (
          <div key={room} className="flex">
            <div className="w-20 shrink-0 py-2 text-sm text-gray-700 dark:text-gray-300">
              {room}
            </div>
            {hours.map(hour => {
              const cellData = data.find(d => d.room === room && d.hour === hour);
              return (
                <div
                  key={`${room}-${hour}`}
                  className="w-12 h-12 border border-gray-200 dark:border-gray-700 flex items-center justify-center text-xs"
                  style={{
                    backgroundColor: cellData ? getUtilizationColor(cellData.utilization) : 'transparent'
                  }}
                >
                  {cellData && `${cellData.utilization}%`}
                </div>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
};

// Capacity planning (line chart: capacity, staffed, demand)
export const CapacityPlanningChart = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
          <XAxis dataKey="date" className="text-gray-600 dark:text-gray-400" />
          <YAxis className="text-gray-600 dark:text-gray-400" />
          <Tooltip
            contentStyle={{ 
              backgroundColor: 'rgb(31, 41, 55)', 
              border: 'none',
              borderRadius: '0.375rem',
              color: 'rgb(243, 244, 246)'
            }}
          />
          <Legend />
          <Line 
            type="monotone" 
            dataKey="capacity" 
            stroke="#3B82F6" 
            strokeWidth={2}
            name="Physical Capacity"
          />
          <Line 
            type="monotone" 
            dataKey="staffed" 
            stroke="#10B981" 
            strokeWidth={2}
            name="Staffed Capacity"
          />
          <Line 
            type="monotone" 
            dataKey="demand" 
            stroke="#EF4444" 
            strokeWidth={2}
            name="Demand"
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
};

// Resource utilization card
export const ResourceUtilizationCard = ({
  resource,
  utilization,
  trend,
  details,
  className = ''
}) => {
  const getStatusColor = (value) => {
    if (value >= 85) return 'text-green-500 dark:text-green-400';
    if (value >= 70) return 'text-yellow-500 dark:text-yellow-400';
    return 'text-red-500 dark:text-red-400';
  };

  return (
    <div className={`
      bg-white dark:bg-gray-800 
      rounded-lg shadow-md p-4
      ${className}
    `}>
      <div className="flex justify-between items-start">
        <div>
          <h4 className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {resource}
          </h4>
          <div className="mt-1 flex items-baseline">
            <p className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
              {utilization}%
            </p>
            <p
              className={`
                ml-2 text-sm font-medium
                ${getStatusColor(utilization)}
              `}
            >
              {trend > 0 ? `+${trend}%` : trend < 0 ? `${trend}%` : 'No change'}
            </p>
          </div>
        </div>
        <div className={getStatusColor(utilization)}>
          {utilization >= 85 ? (
            <TrendingUp className="w-5 h-5" />
          ) : utilization >= 70 ? (
            <Minus className="w-5 h-5" />
          ) : (
            <TrendingDown className="w-5 h-5" />
          )}
        </div>
      </div>

      <div className="mt-4">
        <div className="relative pt-1">
          <div className="overflow-hidden h-2 text-xs flex rounded bg-gray-200 dark:bg-gray-700">
            <div
              style={{ width: `${utilization}%` }}
              className={`
                flex flex-col text-center whitespace-nowrap 
                text-white justify-center transition-all duration-500
                ${utilization >= 85 ? 'bg-green-500' :
                  utilization >= 70 ? 'bg-yellow-500' :
                  'bg-red-500'}
              `}
            />
          </div>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-4">
        {details.map((detail, index) => (
          <div key={index} className="flex flex-col">
            <span className="text-sm text-gray-600 dark:text-gray-400">
              {detail.label}
            </span>
            <span className="mt-1 text-base font-medium text-gray-900 dark:text-gray-100">
              {detail.value}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

// Surgeon performance scatter chart
export const SurgeonPerformanceChart = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        <ScatterChart>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
          <XAxis 
            type="number" 
            dataKey="caseCount" 
            name="Case Count"
            className="text-gray-600 dark:text-gray-400"
          />
          <YAxis 
            type="number" 
            dataKey="turnoverTime" 
            name="Turnover Time"
            unit=" min"
            className="text-gray-600 dark:text-gray-400"
          />
          <Tooltip 
            cursor={{ strokeDasharray: '3 3' }}
            contentStyle={{ 
              backgroundColor: 'rgb(31, 41, 55)', 
              border: 'none',
              borderRadius: '0.375rem',
              color: 'rgb(243, 244, 246)'
            }}
          />
          <Scatter 
            name="Surgeons" 
            data={data}
            fill="#3B82F6"
          >
            {data.map((entry, index) => (
              <Cell
                key={index}
                fill={`rgba(59, 130, 246, ${entry.utilization / 100})`}
              />
            ))}
          </Scatter>
        </ScatterChart>
      </ResponsiveContainer>
    </div>
  );
};

// Case mix analysis
export const CaseMixAnalysis = ({
  data,
  className = ''
}) => {
  return (
    <div className={`space-y-4 ${className}`}>
      {data.map((specialty, index) => (
        <div 
          key={index}
          className="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4"
        >
          <div className="flex justify-between items-start">
            <div>
              <h4 className="font-medium text-gray-900 dark:text-gray-100">
                {specialty.name}
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {specialty.volume} cases • ${specialty.revenue.toLocaleString()} revenue
              </p>
            </div>
            <div
              className={`
                flex items-center text-sm font-medium
                ${specialty.growth >= 0 ? 'text-green-500' : 'text-red-500'}
              `}
            >
              {specialty.growth >= 0 ? (
                <TrendingUp className="w-4 h-4 mr-1" />
              ) : (
                <TrendingDown className="w-4 h-4 mr-1" />
              )}
              {Math.abs(specialty.growth)}%
            </div>
          </div>
          <div className="mt-4 grid grid-cols-3 gap-4">
            {specialty.metrics.map((metric, idx) => (
              <div key={idx} className="text-center">
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  {metric.label}
                </p>
                <p className="mt-1 font-medium text-gray-900 dark:text-gray-100">
                  {metric.value}
                </p>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* SCHEDULING / STAFFING COMPONENTS                                          */
/* ------------------------------------------------------------------------- */

// Composed chart for planned vs actual case durations
export const CaseDurationAnalysis = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
          <XAxis
            dataKey="procedure"
            className="text-gray-600 dark:text-gray-400"
            angle={-45}
            textAnchor="end"
            height={80}
          />
          <YAxis yAxisId="time" label={{ value: 'Duration (min)', angle: -90, position: 'insideLeft' }}/>
          <YAxis yAxisId="cases" orientation="right" label={{ value: 'Cases', angle: 90, position: 'insideRight' }}/>
          <Tooltip
            contentStyle={{ 
              backgroundColor: 'rgb(31, 41, 55)', 
              border: 'none',
              borderRadius: '0.375rem',
              color: 'rgb(243, 244, 246)'
            }}
            formatter={(val) => `${val}`}
          />
          <Legend />
          <Bar yAxisId="time" dataKey="planned" fill="#3B82F6" name="Planned Duration" />
          <Bar yAxisId="time" dataKey="actual" fill="#10B981" name="Actual Duration" />
          <Line yAxisId="cases" type="monotone" dataKey="cases" stroke="#EF4444" strokeWidth={2} name="Case Count" />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
};

// Grid showing resource assignment and utilization
export const ResourceAllocationGrid = ({
  resources,
  className = ''
}) => {
  return (
    <div className={`space-y-4 ${className}`}>
      {resources.map((resource, index) => (
        <div 
          key={index}
          className="bg-white dark:bg-gray-800 rounded-lg shadow p-4"
        >
          <div className="flex items-center justify-between">
            <div>
              <h4 className="font-medium text-gray-900 dark:text-gray-100">
                {resource.name}
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {resource.type}
              </p>
            </div>
            <div className="flex items-center space-x-4">
              <div className="text-right">
                <p className="text-sm text-gray-600 dark:text-gray-400">Utilization</p>
                <p
                  className={`
                    font-medium
                    ${
                      resource.utilization >= 85 ? 'text-green-500 dark:text-green-400' :
                      resource.utilization >= 70 ? 'text-yellow-500 dark:text-yellow-400' :
                      'text-red-500 dark:text-red-400'
                    }
                  `}
                >
                  {resource.utilization}%
                </p>
              </div>
              <div className="h-12 w-2 rounded-full overflow-hidden bg-gray-200 dark:bg-gray-700">
                <div 
                  className={`
                    ${
                      resource.utilization >= 85 ? 'bg-green-500' :
                      resource.utilization >= 70 ? 'bg-yellow-500' :
                      'bg-red-500'
                    }
                  `}
                  style={{ height: `${resource.utilization}%` }}
                />
              </div>
            </div>
          </div>
          <div className="mt-4">
            <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Today's Assignments
            </div>
            <div className="grid grid-cols-4 gap-2">
              {resource.assignments.map((assignment, idx) => (
                <div 
                  key={idx}
                  className={`
                    px-2 py-1 rounded text-xs text-center
                    ${
                      assignment.status === 'completed' 
                        ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100'
                        : assignment.status === 'in-progress'
                        ? 'bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100'
                        : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100'
                    }
                  `}
                >
                  {assignment.time}
                </div>
              ))}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

// Table showing block schedule usage
export const BlockScheduleAnalysis = ({
  data,
  className = ''
}) => {
  return (
    <div className={`overflow-hidden ${className}`}>
      <div className="flex flex-col">
        <div className="-my-2 overflow-x-auto">
          <div className="py-2 align-middle inline-block min-w-full px-2">
            <div className="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 rounded-lg">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-800">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Surgeon/Service
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Assigned Hours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Used Hours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Released Hours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Efficiency
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                  {data.map((row, index) => (
                    <tr key={index}>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {row.surgeon}
                        </div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                          {row.service}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {row.assigned}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {row.used}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {row.released}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center">
                          <span
                            className={`
                              text-sm font-medium
                              ${
                                row.efficiency >= 85 ? 'text-green-500' :
                                row.efficiency >= 70 ? 'text-yellow-500' :
                                'text-red-500'
                              }
                            `}
                          >
                            {row.efficiency}%
                          </span>
                          <div className="ml-4 w-24 h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                              className={`
                                h-full rounded-full
                                ${
                                  row.efficiency >= 85 ? 'bg-green-500' :
                                  row.efficiency >= 70 ? 'bg-yellow-500' :
                                  'bg-red-500'
                                }
                              `}
                              style={{ width: `${row.efficiency}%` }}
                            />
                          </div>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// Staffing variance analysis chart
export const StaffingVarianceAnalysis = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`h-[${height}px] ${className}`}>
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
          <XAxis 
            dataKey="date" 
            className="text-gray-600 dark:text-gray-400"
          />
          <YAxis 
            className="text-gray-600 dark:text-gray-400"
            label={{ value: 'Staff Count', angle: -90, position: 'insideLeft' }}
          />
          <Tooltip
            contentStyle={{
              backgroundColor: 'rgb(31, 41, 55)',
              border: 'none',
              borderRadius: '0.375rem',
              color: 'rgb(243, 244, 246)'
            }}
          />
          <Legend />
          <Area
            type="monotone"
            dataKey="required"
            fill="#3B82F6"
            stroke="#3B82F6"
            name="Required Staff"
            fillOpacity={0.2}
          />
          <Line
            type="monotone"
            dataKey="actual"
            stroke="#10B981"
            strokeWidth={2}
            name="Actual Staff"
          />
          <Bar
            dataKey="variance"
            fill="#EF4444"
            name="Variance"
            opacity={0.5}
          />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* REAL-TIME STATUS & FINANCIAL ANALYTICS                                    */
/* ------------------------------------------------------------------------- */

// OR status board
export const ORStatusBoard = ({
  rooms,
  className = ''
}) => {
  const getStatusColor = (status) => {
    const colors = {
      'in-progress': 'bg-green-100 dark:bg-green-800 text-green-800 dark:text-green-100',
      'turnover': 'bg-yellow-100 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-100',
      'delayed': 'bg-red-100 dark:bg-red-800 text-red-800 dark:text-red-100',
      'available': 'bg-blue-100 dark:bg-blue-800 text-blue-800 dark:text-blue-100',
      'blocked': 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100'
    };
    return colors[status] || colors['available'];
  };

  return (
    <div className={`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 ${className}`}>
      {rooms.map((room, index) => (
        <div 
          key={index}
          className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4"
        >
          <div className="flex justify-between items-start mb-4">
            <div>
              <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {room.room}
              </h3>
              <span
                className={`inline-block px-2 py-1 rounded text-sm ${getStatusColor(room.status)}`}
              >
                {room.status.charAt(0).toUpperCase() + room.status.slice(1)}
              </span>
            </div>
            {room.progress !== undefined && (
              <div className="text-right">
                <div className="text-sm text-gray-600 dark:text-gray-400">
                  Progress
                </div>
                <div className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                  {room.progress}%
                </div>
              </div>
            )}
          </div>

          {room.case && (
            <div className="space-y-2">
              <div className="text-sm">
                <span className="text-gray-600 dark:text-gray-400">Case: </span>
                <span className="text-gray-900 dark:text-gray-100">{room.case}</span>
              </div>
              <div className="text-sm">
                <span className="text-gray-600 dark:text-gray-400">Surgeon: </span>
                <span className="text-gray-900 dark:text-gray-100">{room.surgeon}</span>
              </div>
              <div className="flex justify-between text-sm">
                <div>
                  <span className="text-gray-600 dark:text-gray-400">Start: </span>
                  <span className="text-gray-900 dark:text-gray-100">{room.startTime}</span>
                </div>
                <div>
                  <span className="text-gray-600 dark:text-gray-400">Duration: </span>
                  <span className="text-gray-900 dark:text-gray-100">{room.duration} min</span>
                </div>
              </div>
              {room.progress !== undefined && (
                <div className="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                  <div 
                    className="h-full bg-blue-500"
                    style={{ width: `${room.progress}%` }}
                  />
                </div>
              )}
            </div>
          )}
        </div>
      ))}
    </div>
  );
};

// Financial analytics
export const FinancialAnalytics = ({
  metrics,
  trends,
  bySpecialty,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`space-y-6 ${className}`}>
      {/* Key Financial Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        {Object.entries(metrics).map(([key, value]) => (
          <div 
            key={key}
            className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4"
          >
            <div className="flex items-center justify-between">
              <div className="text-sm text-gray-600 dark:text-gray-400">
                {key.split(/(?=[A-Z])/).join(' ')}
              </div>
              <DollarSign className="w-5 h-5 text-gray-400 dark:text-gray-500" />
            </div>
            <div className="mt-2 flex items-baseline">
              <div className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                ${value.value.toLocaleString()}
              </div>
              <span
                className={`
                  ml-2 text-sm font-medium
                  ${
                    value.trend > 0 ? 'text-green-600 dark:text-green-400' :
                    value.trend < 0 ? 'text-red-600 dark:text-red-400' :
                    'text-gray-600 dark:text-gray-400'
                  }
                `}
              >
                {value.trend > 0 ? '+' : ''}{value.trend}%
              </span>
            </div>
          </div>
        ))}
      </div>

      {/* Revenue & Cost Trends */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
          Revenue and Cost Trends
        </h3>
        <div style={{ height: `${height}px` }}>
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={trends}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
              <XAxis dataKey="date" className="text-gray-600 dark:text-gray-400" />
              <YAxis
                className="text-gray-600 dark:text-gray-400"
                tickFormatter={(value) => `$${value.toLocaleString()}`}
              />
              <Tooltip
                formatter={(value) => `$${value.toLocaleString()}`}
                contentStyle={{
                  backgroundColor: 'rgb(31, 41, 55)',
                  border: 'none',
                  borderRadius: '0.375rem',
                  color: 'rgb(243, 244, 246)'
                }}
              />
              <Legend />
              <Area
                type="monotone"
                dataKey="revenue"
                fill="#3B82F6"
                stroke="#3B82F6"
                fillOpacity={0.2}
                name="Revenue"
              />
              <Line
                type="monotone"
                dataKey="costs"
                stroke="#EF4444"
                name="Costs"
              />
              <Line
                type="monotone"
                dataKey="margin"
                stroke="#10B981"
                name="Margin"
              />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Specialty Financial Analysis */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        <div className="px-4 py-5 sm:px-6">
          <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            Specialty Financial Analysis
          </h3>
        </div>
        <div className="border-t border-gray-200 dark:border-gray-700">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-900">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Specialty
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Revenue
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Cases
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Cost per Case
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  Contribution
                </th>
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              {bySpecialty.map((specialty, index) => (
                <tr key={index}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                    {specialty.specialty}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                    ${specialty.revenue.toLocaleString()}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                    {specialty.cases}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                    ${specialty.costPerCase.toLocaleString()}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="flex items-center">
                      <span className="text-sm text-gray-900 dark:text-gray-100">
                        {specialty.contribution}%
                      </span>
                      <div className="ml-4 flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full">
                        <div
                          className="h-full bg-blue-500 rounded-full"
                          style={{ width: `${specialty.contribution}%` }}
                        />
                      </div>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* PREDICTIVE ANALYTICS & OPTIMIZATION                                       */
/* ------------------------------------------------------------------------- */

// Volume Forecast
export const VolumeForecast = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Volume Forecast - Next 30 Days
      </h3>
      <div style={{ height: `${height}px` }}>
        <ResponsiveContainer width="100%" height="100%">
          <ComposedChart data={data}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
            <XAxis dataKey="date" className="text-gray-600 dark:text-gray-400" />
            <YAxis className="text-gray-600 dark:text-gray-400" />
            <Tooltip
              contentStyle={{ 
                backgroundColor: 'rgb(31, 41, 55)', 
                border: 'none',
                borderRadius: '0.375rem',
                color: 'rgb(243, 244, 246)'
              }}
            />
            <Legend />
            <Area
              type="monotone"
              dataKey="predicted"
              stroke="#3B82F6"
              fill="#3B82F6"
              fillOpacity={0.1}
              name="Predicted Volume"
            />
            <Line 
              type="monotone" 
              dataKey="actual" 
              stroke="#10B981"
              strokeWidth={2}
              name="Actual Volume"
            />
            {/* Confidence interval areas (upper/lower) */}
            <Area
              type="monotone"
              dataKey="upper"
              strokeOpacity={0}
              stroke="#3B82F6"
              fill="#3B82F6"
              fillOpacity={0.1}
              name="Confidence Interval"
            />
            <Area
              type="monotone"
              dataKey="lower"
              strokeOpacity={0}
              stroke="#3B82F6"
              fill="#3B82F6"
              fillOpacity={0.1}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
};

// Anomaly Detection card
export const AnomalyDetection = ({
  anomalies,
  className = ''
}) => {
  const getSeverityColor = (severity) => {
    switch (severity) {
      case 'high': return 'text-red-500 dark:text-red-400';
      case 'medium': return 'text-yellow-500 dark:text-yellow-400';
      case 'low': return 'text-blue-500 dark:text-blue-400';
      default: return 'text-gray-500 dark:text-gray-400';
    }
  };

  return (
    <div className={`space-y-4 ${className}`}>
      {anomalies.map((anomaly, index) => (
        <div 
          key={index}
          className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4"
        >
          <div className="flex items-start justify-between">
            <div>
              <h4 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {anomaly.metric}
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Current value vs Expected
              </p>
            </div>
            <AlertTriangle className={`w-5 h-5 ${getSeverityColor(anomaly.severity)}`} />
          </div>
          <div className="mt-4 grid grid-cols-2 gap-4">
            <div>
              <div className="text-sm text-gray-600 dark:text-gray-400">Current Value</div>
              <div className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                {anomaly.value}
              </div>
            </div>
            <div>
              <div className="text-sm text-gray-600 dark:text-gray-400">Expected Range</div>
              <div className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                {anomaly.expected}
              </div>
            </div>
          </div>
          <div className="mt-4">
            <div className="text-sm text-gray-600 dark:text-gray-400">
              Deviation
            </div>
            <div className="flex items-center mt-1">
              <span className={`text-lg font-semibold ${getSeverityColor(anomaly.severity)}`}>
                {anomaly.deviation > 0 ? '+' : ''}{anomaly.deviation}%
              </span>
              {anomaly.trend === 'up' ? (
                <TrendingUp className={`w-4 h-4 ml-2 ${getSeverityColor(anomaly.severity)}`} />
              ) : (
                <TrendingDown className={`w-4 h-4 ml-2 ${getSeverityColor(anomaly.severity)}`} />
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
};

// Radar chart for operational efficiency
export const EfficiencyRadar = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Operational Efficiency Metrics
      </h3>
      <div style={{ height: `${height}px` }}>
        <ResponsiveContainer width="100%" height="100%">
          <RadarChart cx="50%" cy="50%" outerRadius="80%" data={data}>
            <PolarGrid className="stroke-gray-200 dark:stroke-gray-700" />
            <PolarAngleAxis dataKey="metric" className="text-gray-600 dark:text-gray-400" />
            <PolarRadiusAxis className="text-gray-600 dark:text-gray-400" />
            <Radar
              name="Current"
              dataKey="value"
              stroke="#3B82F6"
              fill="#3B82F6"
              fillOpacity={0.2}
            />
            <Radar
              name="Benchmark"
              dataKey="benchmark"
              stroke="#10B981"
              fill="#10B981"
              fillOpacity={0.2}
            />
            <Legend />
          </RadarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
};

// Predictive capacity risk assessment
export const CapacityPrediction = ({
  predictions,
  className = ''
}) => {
  const getRiskColor = (risk) => {
    switch (risk) {
      case 'high':
        return 'bg-red-100 dark:bg-red-800 text-red-800 dark:text-red-100';
      case 'medium':
        return 'bg-yellow-100 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-100';
      case 'low':
        return 'bg-green-100 dark:bg-green-800 text-green-800 dark:text-green-100';
      default:
        return 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100';
    }
  };

  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Capacity Risk Assessment - Next Week
      </h3>
      <div className="space-y-4">
        {predictions.map((prediction, index) => (
          <div
            key={index}
            className="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-900"
          >
            <div>
              <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {prediction.timeSlot}
              </div>
              <div className="text-sm text-gray-600 dark:text-gray-400">
                Predicted Demand: {prediction.predictedDemand} cases
              </div>
            </div>
            <div className="text-right">
              <div className="text-sm text-gray-600 dark:text-gray-400">
                Available Capacity: {prediction.availableCapacity} cases
              </div>
              <span
                className={`
                  inline-block px-2 py-1 rounded text-xs font-medium mt-1
                  ${getRiskColor(prediction.risk)}
                `}
              >
                {prediction.risk.toUpperCase()} RISK
              </span>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

// Scheduling optimization suggestions
export const SchedulingOptimization = ({
  suggestions,
  className = ''
}) => {
  return (
    <div className={`space-y-4 ${className}`}>
      {suggestions.map((suggestion, index) => (
        <div 
          key={index}
          className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4"
        >
          <div className="flex items-start justify-between">
            <div>
              <h4 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {suggestion.type}
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Potential Impact: +{suggestion.impact}% efficiency
              </p>
            </div>
            <div className="text-green-500 dark:text-green-400">
              <TrendingUp className="w-5 h-5" />
            </div>
          </div>
          <p className="mt-3 text-gray-700 dark:text-gray-300">
            {suggestion.description}
          </p>
          <div className="mt-4 space-y-2">
            {suggestion.actions.map((action, actionIndex) => (
              <div 
                key={actionIndex}
                className="flex items-center text-sm text-gray-600 dark:text-gray-400"
              >
                <Check className="w-4 h-4 mr-2 text-green-500" />
                {action}
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* EVEN MORE ADVANCED: RESOURCE OPT. MATRIX, CROSS-DEPT, BLOCK TIME ETC.     */
/* ------------------------------------------------------------------------- */

// Resource optimization matrix
export const ResourceOptimizationMatrix = ({
  resources,
  height = 400,
  className = ''
}) => {
  const utilizationThreshold = 75;
  const efficiencyThreshold = 75;

  const getQuadrant = (utilization, efficiency) => {
    if (utilization >= utilizationThreshold && efficiency >= efficiencyThreshold) {
      return 'Optimal';
    } else if (utilization >= utilizationThreshold && efficiency < efficiencyThreshold) {
      return 'Overloaded';
    } else if (utilization < utilizationThreshold && efficiency >= efficiencyThreshold) {
      return 'Underutilized';
    } else {
      return 'Review';
    }
  };

  const getQuadrantColor = (quadrant) => {
    switch (quadrant) {
      case 'Optimal':
        return '#10B981'; 
      case 'Overloaded':
        return '#EF4444';
      case 'Underutilized':
        return '#F59E0B';
      case 'Review':
        return '#6B7280';
      default:
        return '#3B82F6';
    }
  };

  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Resource Optimization Matrix
      </h3>
      <div style={{ height: `${height}px` }}>
        <ResponsiveContainer width="100%" height="100%">
          <ScatterChart margin={{ top: 20, right: 20, bottom: 20, left: 20 }}>
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
            <XAxis
              type="number"
              dataKey="utilization"
              name="Utilization"
              unit="%"
              domain={[0, 100]}
              className="text-gray-600 dark:text-gray-400"
            />
            <YAxis
              type="number"
              dataKey="efficiency"
              name="Efficiency"
              unit="%"
              domain={[0, 100]}
              className="text-gray-600 dark:text-gray-400"
            />
            <Tooltip
              cursor={{ strokeDasharray: '3 3' }}
              contentStyle={{ 
                backgroundColor: 'rgb(31, 41, 55)', 
                border: 'none',
                borderRadius: '0.375rem',
                color: 'rgb(243, 244, 246)'
              }}
              formatter={(value) => `${value}%`}
            />
            <Scatter data={resources} fill="#3B82F6">
              {resources.map((entry, index) => {
                const quadrant = getQuadrant(entry.utilization, entry.efficiency);
                return (
                  <Cell
                    key={index}
                    fill={getQuadrantColor(quadrant)}
                  />
                );
              })}
            </Scatter>
            <ReferenceLine x={utilizationThreshold} stroke="#6B7280" strokeDasharray="3 3" />
            <ReferenceLine y={efficiencyThreshold} stroke="#6B7280" strokeDasharray="3 3" />
          </ScatterChart>
        </ResponsiveContainer>
      </div>
      {/* Legend */}
      <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
        {['Optimal', 'Overloaded', 'Underutilized', 'Review'].map((quadrant) => (
          <div key={quadrant} className="flex items-center">
            <div 
              className="w-3 h-3 rounded-full mr-2"
              style={{ backgroundColor: getQuadrantColor(quadrant) }}
            />
            <span className="text-sm text-gray-600 dark:text-gray-400">
              {quadrant}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

// Cross-department impact analysis
export const CrossDepartmentAnalysis = ({
  data,
  className = ''
}) => {
  const getImpactColor = (impact) => {
    if (impact >= 75) return 'text-green-500 dark:text-green-400';
    if (impact >= 50) return 'text-yellow-500 dark:text-yellow-400';
    return 'text-red-500 dark:text-red-400';
  };

  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Cross-Department Impact Analysis
      </h3>
      <div className="space-y-6">
        {data.map((dept, index) => (
          <div key={index} className="border-b border-gray-200 dark:border-gray-700 pb-6 last:border-0">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h4 className="text-base font-medium text-gray-900 dark:text-gray-100">
                  {dept.department}
                </h4>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  Impact Score: 
                  <span className={`ml-2 font-medium ${getImpactColor(dept.impact)}`}>
                    {dept.impact}%
                  </span>
                </p>
              </div>
              <div className={getImpactColor(dept.impact)}>
                {dept.impact >= 75 ? (
                  <TrendingUp className="w-5 h-5" />
                ) : dept.impact >= 50 ? (
                  <Minus className="w-5 h-5" />
                ) : (
                  <TrendingDown className="w-5 h-5" />
                )}
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {dept.metrics.map((metric, metricIndex) => (
                <div key={metricIndex} className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                  <div className="text-sm text-gray-600 dark:text-gray-400">
                    {metric.name}
                  </div>
                  <div className="mt-1 flex items-baseline">
                    <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                      {metric.value}
                    </span>
                    <span
                      className={`
                        ml-2 text-sm font-medium
                        ${
                          metric.change > 0 ? 'text-green-500 dark:text-green-400' :
                          metric.change < 0 ? 'text-red-500 dark:text-red-400' :
                          'text-gray-500 dark:text-gray-400'
                        }
                      `}
                    >
                      {metric.change > 0 ? '+' : ''}{metric.change}%
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

// Horizontal bar chart for block time optimization
export const BlockTimeOptimization = ({
  data,
  height = 400,
  className = ''
}) => {
  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Block Time Optimization Analysis
      </h3>
      <div style={{ height: `${height}px` }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={data}
            layout="vertical"
            margin={{ top: 20, right: 30, left: 100, bottom: 5 }}
          >
            <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-gray-700" />
            <XAxis type="number" className="text-gray-600 dark:text-gray-400" />
            <YAxis type="category" dataKey="service" className="text-gray-600 dark:text-gray-400" />
            <Tooltip
              contentStyle={{
                backgroundColor: 'rgb(31, 41, 55)',
                border: 'none',
                borderRadius: '0.375rem',
                color: 'rgb(243, 244, 246)'
              }}
            />
            <Legend />
            <Bar dataKey="utilized" stackId="a" fill="#10B981" name="Utilized" />
            <Bar dataKey="released" stackId="a" fill="#EF4444" name="Released" />
            <Bar dataKey="unused" stackId="a" fill="#6B7280" name="Unused" />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <div className="mt-4 grid grid-cols-2 md:grid-cols-3 gap-4">
        {data.map((service, index) => (
          <div key={index} className="flex items-center justify-between">
            <span className="text-sm text-gray-600 dark:text-gray-400">
              {service.service}
            </span>
            <span
              className={`
                text-sm font-medium
                ${
                  service.efficiency >= 85 ? 'text-green-500' :
                  service.efficiency >= 70 ? 'text-yellow-500' :
                  'text-red-500'
                }
              `}
            >
              {service.efficiency}%
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

// Surgeon performance analysis with line chart trend
export const SurgeonPerformanceAnalysis = ({
  data,
  className = ''
}) => {
  const calculateScore = (metrics) => {
    // Weighted example
    const weights = {
      utilization: 0.3,
      onTime: 0.2,
      turnover: 0.2,
      cases: 0.15,
      satisfaction: 0.15
    };
    return Object.entries(metrics).reduce((score, [key, value]) => {
      return score + (value * weights[key] || 0);
    }, 0);
  };

  return (
    <div className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 ${className}`}>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Surgeon Performance Analysis
      </h3>
      <div className="space-y-6">
        {data.map((surgeon, index) => {
          const score = calculateScore(surgeon.metrics);
          return (
            <div 
              key={index}
              className="border-b border-gray-200 dark:border-gray-700 pb-6 last:border-0"
            >
              <div className="flex justify-between items-start mb-4">
                <div>
                  <h4 className="text-base font-medium text-gray-900 dark:text-gray-100">
                    {surgeon.name}
                  </h4>
                  <p className="text-sm text-gray-600 dark:text-gray-400">
                    {surgeon.specialty}
                  </p>
                </div>
                <div
                  className={`
                    px-3 py-1 rounded-full text-sm font-medium
                    ${
                      score >= 85 
                        ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100'
                        : score >= 70 
                        ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100'
                        : 'bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100'
                    }
                  `}
                >
                  Score: {score.toFixed(1)}
                </div>
              </div>
              {/* Metrics Grid */}
              <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                {Object.entries(surgeon.metrics).map(([key, value], metricIndex) => (
                  <div key={metricIndex} className="text-center">
                    <div className="text-sm text-gray-600 dark:text-gray-400">
                      {key.charAt(0).toUpperCase() + key.slice(1)}
                    </div>
                    <div
                      className={`
                        mt-1 text-lg font-medium
                        ${
                          value >= 85 ? 'text-green-500 dark:text-green-400' :
                          value >= 70 ? 'text-yellow-500 dark:text-yellow-400' :
                          'text-red-500 dark:text-red-400'
                        }
                      `}
                    >
                      {value}%
                    </div>
                  </div>
                ))}
              </div>
              {/* Trend line chart */}
              <div className="mt-4 h-24">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={surgeon.trend}>
                    <Line
                      type="monotone"
                      dataKey="score"
                      stroke="#3B82F6"
                      strokeWidth={2}
                      dot={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

/* ------------------------------------------------------------------------- */
/* EXAMPLE PAGE BRINGING IT ALL TOGETHER                                     */
/* ------------------------------------------------------------------------- */

/**
 * MAIN PAGE COMPONENT
 * 
 * This "DesignCardsPage" includes a series of sections that demonstrate 
 * how the above components might be used together in a cohesive design. 
 * It is intentionally verbose for demonstration and review.
 */
export default function DesignCardsPage() {
  // Local state for toggling examples/demos
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [dateRange, setDateRange] = useState('month');
  const [isDarkMode, toggleDarkMode] = useDarkMode();

  /* Sample data for demonstration across sections */
  const samplePatient = {
    name: "John Doe",
    mrn: "MRN12345",
    age: "45y",
    gender: "Male",
    dob: "1978-06-15",
    location: "Ward 3B"
  };
  const sampleVitals = [
    { name: "Blood Pressure", value: "120/80", unit: "mmHg", status: "normal" },
    { name: "Heart Rate", value: "72", unit: "bpm", status: "normal" },
    { name: "Temperature", value: "98.6", unit: "°F", status: "normal" },
    { name: "SpO2", value: "98", unit: "%", status: "normal" }
  ];
  const sampleCategories = [
    { id: 1, name: "Labs", description: "Common laboratory orders" },
    { id: 2, name: "Imaging", description: "Radiology and imaging studies" },
    { id: 3, name: "Medications", description: "Medication orders" }
  ];
  const sampleSelectedOrders = [];
  const sampleEvents = [
    {
      type: "medication",
      timestamp: "2024-02-08 09:00",
      title: "Medication Administration",
      description: "Administered 500mg Acetaminophen"
    },
    {
      type: "procedure",
      timestamp: "2024-02-08 10:30",
      title: "Vital Signs Check",
      description: "Routine vital signs recorded"
    }
  ];
  const sampleData = [
    { month: 'Jan', cases: 100, procedures: 80 },
    { month: 'Feb', cases: 120, procedures: 90 },
    { month: 'Mar', cases: 140, procedures: 100 },
    { month: 'Apr', cases: 130, procedures: 95 },
  ];
  const sampleFilters = [
    {
      id: 'dateRange',
      label: 'Date Range',
      type: 'dateRange',
      value: { start: '2024-01-01', end: '2024-12-31' }
    },
    {
      id: 'department',
      label: 'Department',
      type: 'select',
      value: 'all',
      options: [
        { value: 'all', label: 'All Departments' },
        { value: 'surgery', label: 'Surgery' },
        { value: 'cardiology', label: 'Cardiology' }
      ]
    }
  ];
  const blockUtilizationData = [
    { room: 'OR 1', utilized: 0.65, turnover: 0.15, unused: 0.10, blocked: 0.10 },
    { room: 'OR 2', utilized: 0.75, turnover: 0.10, unused: 0.05, blocked: 0.10 },
    { room: 'OR 3', utilized: 0.55, turnover: 0.20, unused: 0.15, blocked: 0.10 },
  ];
  const surgicalCases = [
    {
      procedure: 'Total Knee Replacement',
      surgeon: 'Dr. Smith',
      room: 'OR 1',
      time: '08:00 AM',
      duration: 120,
      progress: 75,
      startTime: '08:00',
      endTime: '10:00'
    },
    {
      procedure: 'Laparoscopic Cholecystectomy',
      surgeon: 'Dr. Johnson',
      room: 'OR 2',
      time: '09:30 AM',
      duration: 90,
      progress: 50,
      startTime: '09:30',
      endTime: '11:00'
    }
  ];
  const serviceLineMetrics = {
    serviceLine: 'Orthopedics',
    metrics: [
      { label: 'Cases', value: '245', trend: 'up', change: '+12%' },
      { label: 'Utilization', value: '85%', trend: 'up', change: '+5%' },
      { label: 'Turnover Time', value: '25m', trend: 'down', change: '-3m' },
      { label: 'First Case Delay', value: '8%', trend: 'neutral', change: '0%' }
    ]
  };
  const heatmapData = Array.from({ length: 12 }, (_, hour) =>
    Array.from({ length: 5 }, (_, room) => ({
      hour: hour + 7,
      room: `OR ${room + 1}`,
      utilization: Math.floor(Math.random() * 100)
    }))
  ).flat();
  const capacityData = Array.from({ length: 7 }, (_, i) => ({
    date: `Day ${i + 1}`,
    capacity: 100,
    staffed: 85 + Math.random() * 10,
    demand: 70 + Math.random() * 30
  }));
  const resourceUtilCardData = {
    resource: 'OR Utilization',
    utilization: 85,
    trend: 2.5,
    details: [
      { label: 'Total Cases', value: '245' },
      { label: 'Avg Duration', value: '125 min' },
      { label: 'First Case On-Time', value: '92%' },
      { label: 'Turnover Time', value: '25 min' }
    ]
  };
  const surgeonData = Array.from({ length: 20 }, (_, i) => ({
    surgeon: `Surgeon ${i + 1}`,
    caseCount: Math.floor(Math.random() * 100),
    turnoverTime: Math.floor(Math.random() * 60),
    utilization: Math.floor(Math.random() * 100)
  }));
  const caseMixData = [
    {
      name: 'Orthopedics',
      volume: 245,
      revenue: 2500000,
      growth: 12,
      metrics: [
        { label: 'Avg Duration', value: '120 min' },
        { label: 'Utilization', value: '85%' },
        { label: 'Turnover', value: '25 min' }
      ]
    },
    {
      name: 'General Surgery',
      volume: 312,
      revenue: 1800000,
      growth: -5,
      metrics: [
        { label: 'Avg Duration', value: '90 min' },
        { label: 'Utilization', value: '78%' },
        { label: 'Turnover', value: '22 min' }
      ]
    }
  ];
  const caseDurationData = [
    { procedure: 'Total Knee', planned: 120, actual: 135, cases: 45 },
    { procedure: 'Lap Chole', planned: 90, actual: 85, cases: 62 },
    { procedure: 'Cataracts', planned: 45, actual: 42, cases: 85 }
  ];
  const resourceGridData = [
    {
      name: 'OR Team 1',
      type: 'Surgical Team',
      utilization: 88,
      assignments: [
        { time: '07:30', status: 'completed' },
        { time: '09:30', status: 'in-progress' },
        { time: '11:30', status: 'scheduled' },
        { time: '13:30', status: 'scheduled' }
      ]
    },
    {
      name: 'OR Team 2',
      type: 'Surgical Team',
      utilization: 75,
      assignments: [
        { time: '07:30', status: 'completed' },
        { time: '10:30', status: 'in-progress' },
        { time: '13:30', status: 'scheduled' }
      ]
    }
  ];
  const blockScheduleData = [
    {
      surgeon: 'Dr. Smith',
      service: 'Orthopedics',
      assigned: '40',
      used: '36',
      released: '4',
      efficiency: 90
    },
    {
      surgeon: 'Dr. Johnson',
      service: 'General Surgery',
      assigned: '32',
      used: '24',
      released: '8',
      efficiency: 75
    }
  ];
  const staffingData = Array.from({ length: 7 }, (_, i) => ({
    date: `Day ${i + 1}`,
    required: 20,
    actual: 18 + Math.random() * 4,
    variance: Math.random() * 2 - 1
  }));
  const orStatusRooms = [
    {
      room: 'OR 1',
      status: 'in-progress',
      case: 'Total Knee Replacement',
      surgeon: 'Dr. Smith',
      startTime: '08:00',
      duration: 120,
      progress: 75
    },
    {
      room: 'OR 2',
      status: 'turnover',
      progress: 10
    },
    {
      room: 'OR 3',
      status: 'available'
    }
  ];
  const financialMetrics = {
    totalRevenue: { value: 2000000, trend: 5 },
    totalCosts: { value: 1500000, trend: 3 },
    netMargin: { value: 500000, trend: 8 },
    averageRevenuePerCase: { value: 4500, trend: 2 }
  };
  const financialTrends = [
    { date: 'Jan', revenue: 400000, costs: 300000, margin: 100000 },
    { date: 'Feb', revenue: 450000, costs: 320000, margin: 130000 },
    { date: 'Mar', revenue: 500000, costs: 350000, margin: 150000 },
    { date: 'Apr', revenue: 550000, costs: 380000, margin: 170000 },
  ];
  const specialtyFinancials = [
    { specialty: 'Orthopedics', revenue: 1000000, cases: 220, costPerCase: 3000, contribution: 60 },
    { specialty: 'General Surgery', revenue: 600000, cases: 180, costPerCase: 2500, contribution: 55 },
  ];
  const forecastData = Array.from({ length: 30 }, (_, i) => ({
    date: `Day ${i + 1}`,
    actual: i < 15 ? Math.floor(50 + Math.random() * 20) : null,
    predicted: Math.floor(55 + Math.random() * 15),
    upper: Math.floor(65 + Math.random() * 15),
    lower: Math.floor(45 + Math.random() * 15)
  }));
  const anomalyData = [
    {
      metric: 'First Case On-Time Starts',
      value: '68%',
      expected: '85-90%',
      deviation: -20,
      severity: 'high',
      trend: 'down'
    },
    {
      metric: 'Turnover Time',
      value: '32 min',
      expected: '25-30 min',
      deviation: 15,
      severity: 'medium',
      trend: 'up'
    }
  ];
  const efficiencyData = [
    { metric: 'Utilization', value: 85, benchmark: 90 },
    { metric: 'On-Time Starts', value: 75, benchmark: 95 },
    { metric: 'Turnover', value: 82, benchmark: 85 },
    { metric: 'Case Duration', value: 88, benchmark: 90 },
    { metric: 'Schedule Accuracy', value: 78, benchmark: 85 }
  ];
  const capacityPredictData = [
    {
      timeSlot: 'Monday AM',
      predictedDemand: 12,
      availableCapacity: 10,
      risk: 'high'
    },
    {
      timeSlot: 'Monday PM',
      predictedDemand: 8,
      availableCapacity: 10,
      risk: 'low'
    }
  ];
  const schedulingSuggestions = [
    {
      type: 'Block Schedule Optimization',
      impact: 15,
      description: 'Reallocate underutilized blocks based on historical patterns',
      actions: [
        'Adjust block allocation for Service A',
        'Release blocks earlier',
        'Implement flex block policy'
      ]
    },
    {
      type: 'Staffing Shuffle',
      impact: 10,
      description: 'Reschedule staff shifts to align with predicted peak demand',
      actions: [
        'Move shift start times by 30 min for Team B',
        'Add on-call staff for Monday AM high demand',
      ]
    },
  ];
  const resourceOptData = [
    { name: 'Resource 1', utilization: 80, efficiency: 78, cost: 1200, volume: 30 },
    { name: 'Resource 2', utilization: 90, efficiency: 85, cost: 1400, volume: 45 },
    { name: 'Resource 3', utilization: 60, efficiency: 70, cost: 900, volume: 20 },
    { name: 'Resource 4', utilization: 95, efficiency: 60, cost: 1600, volume: 55 },
  ];
  const crossDeptData = [
    {
      department: 'Pre-Op',
      impact: 78,
      metrics: [
        { name: 'Throughput', value: 40, change: 10 },
        { name: 'Delays', value: 5, change: -2 },
      ]
    },
    {
      department: 'PACU',
      impact: 62,
      metrics: [
        { name: 'Average LOS', value: 2.5, change: 0.2 },
        { name: 'Transfers', value: 8, change: -1 }
      ]
    }
  ];
  const blockTimeData = [
    {
      service: 'Ortho',
      utilized: 30,
      released: 5,
      unused: 5,
      efficiency: 75
    },
    {
      service: 'Gen Surg',
      utilized: 25,
      released: 10,
      unused: 5,
      efficiency: 60
    }
  ];
  const surgeonPerfData = [
    {
      name: 'Dr. Smith',
      specialty: 'Orthopedics',
      metrics: {
        utilization: 90,
        onTime: 85,
        turnover: 88,
        cases: 75,
        satisfaction: 80
      },
      trend: [
        { score: 80 },
        { score: 82 },
        { score: 85 },
        { score: 88 },
        { score: 90 }
      ]
    },
    {
      name: 'Dr. Johnson',
      specialty: 'General Surgery',
      metrics: {
        utilization: 70,
        onTime: 65,
        turnover: 72,
        cases: 60,
        satisfaction: 68
      },
      trend: [
        { score: 65 },
        { score: 68 },
        { score: 70 },
        { score: 72 },
        { score: 74 }
      ]
    }
  ];

  return (
    <div className="p-6 space-y-6 bg-gray-50 dark:bg-gray-900 min-h-screen relative">
      {/* Dark Mode Toggle */}
      <div className="fixed top-4 right-4 z-50">
        <DarkModeToggle isDarkMode={isDarkMode} onToggle={toggleDarkMode} />
      </div>
      {/* 1. Simple Healthcare UI Components Example */}
      <Panel title="Patient Overview">
        <PatientBanner 
          patient={samplePatient} 
          alertCount={2}
          onAlertClick={() => console.log('Alerts clicked')}
        />
        <VitalSigns vitals={sampleVitals} className="mt-4" />
        <ClinicalNote
          title="Progress Note"
          content="Patient stable, continuing current treatment plan..."
          author="Dr. Smith"
          timestamp="2024-02-08 08:00"
          className="mt-6"
        />
        <OrderEntry
          categories={sampleCategories}
          selectedOrders={sampleSelectedOrders}
          onOrderSelect={(category) => console.log('Selected:', category)}
          className="mt-6"
        />
        <ClinicalTimeline events={sampleEvents} className="mt-6" />
        <CDSAlert
          severity="warning"
          title="Medication Interaction Alert"
          message="Potential interaction detected between current medications"
          recommendations={[
            "Consider alternative medication",
            "Monitor patient closely"
          ]}
          onDismiss={() => console.log('Alert dismissed')}
          className="mt-6"
        />
        <div className="mt-4">
          <HealthcareButton onClick={() => setIsModalOpen(true)}>
            Open Modal
          </HealthcareButton>
          <Modal
            isOpen={isModalOpen}
            onClose={() => setIsModalOpen(false)}
            title="Patient Details"
          >
            <p className="text-gray-600 dark:text-gray-300">
              Modal content goes here. This could contain patient information,
              confirmation messages, or other healthcare-related content.
            </p>
          </Modal>
        </div>
      </Panel>

      {/* 2. Analytics Demo */}
      <DashboardHeader
        title="Healthcare Analytics Dashboard"
        dateRange={dateRange}
        onDateRangeChange={setDateRange}
        onRefresh={() => console.log('Refreshing data...')}
      />

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <MetricCard
          title="Total Cases"
          value="1,234"
          change="+12.3%"
          trend="up"
        />
        <MetricCard
          title="Avg LOS"
          value="3.2 days"
          change="-5.1%"
          trend="down"
        />
        <MetricCard
          title="OR Utilization"
          value="85.4%"
          change="+2.1%"
          trend="up"
        />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="md:col-span-1">
          <FilterPanel
            filters={sampleFilters}
            onFilterChange={(id, value) => console.log(id, value)}
          />
        </div>
        <div className="md:col-span-3">
          <AnalyticsPanel
            title="Case Volume Trends"
            subtitle="Monthly case and procedure volumes"
            actions={[
              {
                label: 'Download',
                icon: <Download className="w-4 h-4" />,
                onClick: () => console.log('Download clicked')
              }
            ]}
          >
            <AnalyticsChart
              type="line"
              data={sampleData}
              xKey="month"
              yKeys={['cases', 'procedures']}
              height={300}
            />
          </AnalyticsPanel>
        </div>
      </div>

      <AnalyticsPanel
        title="Detailed Metrics"
        subtitle="Comprehensive view of key performance indicators"
      >
        <DataTable
          columns={[
            { key: 'month', header: 'Month' },
            { key: 'cases', header: 'Cases' },
            { key: 'procedures', header: 'Procedures' }
          ]}
          data={sampleData}
        />
      </AnalyticsPanel>

      {/* 3. Additional Analytics */}
      <AnalyticsPanel
        title="Block Utilization by Room"
        subtitle="Breakdown of OR time utilization"
      >
        <BlockUtilizationChart data={blockUtilizationData} height={300} />
      </AnalyticsPanel>

      <AnalyticsPanel
        title="Today's Surgical Cases"
        subtitle="Real-time case tracking and progress"
      >
        <SurgicalTimeline cases={surgicalCases} />
      </AnalyticsPanel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <PerformanceIndicator
          label="Block Utilization"
          value={85}
          target={90}
          unit="%"
        />
        <ServiceLineCard
          serviceLine={serviceLineMetrics.serviceLine}
          metrics={serviceLineMetrics.metrics}
        />
      </div>

      {/* 4. Advanced Analytics */}
      <AnalyticsPanel title="OR Heatmap" subtitle="Hourly utilization by room">
        <ORHeatmap data={heatmapData} />
      </AnalyticsPanel>
      <AnalyticsPanel
        title="Capacity Planning"
        subtitle="Physical vs staffed capacity vs demand"
      >
        <CapacityPlanningChart data={capacityData} />
      </AnalyticsPanel>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <ResourceUtilizationCard {...resourceUtilCardData} />
        <AnalyticsPanel
          title="Surgeon Performance Analysis"
          subtitle="Case volume vs turnover time"
        >
          <SurgeonPerformanceChart data={surgeonData} />
        </AnalyticsPanel>
      </div>
      <AnalyticsPanel
        title="Case Mix Analysis"
        subtitle="Breakdown by specialty"
      >
        <CaseMixAnalysis data={caseMixData} />
      </AnalyticsPanel>

      {/* 5. Scheduling & Staffing */}
      <AnalyticsPanel
        title="Case Duration Analysis"
        subtitle="Planned vs Actual Duration by Procedure"
      >
        <CaseDurationAnalysis data={caseDurationData} />
      </AnalyticsPanel>
      <AnalyticsPanel
        title="Resource Allocation"
        subtitle="Current staffing and assignments"
      >
        <ResourceAllocationGrid resources={resourceGridData} />
      </AnalyticsPanel>
      <AnalyticsPanel
        title="Block Schedule Analysis"
        subtitle="Assigned vs used hours"
      >
        <BlockScheduleAnalysis data={blockScheduleData} />
      </AnalyticsPanel>
      <AnalyticsPanel
        title="Staffing Variance Analysis"
        subtitle="Required vs actual staff"
      >
        <StaffingVarianceAnalysis data={staffingData} />
      </AnalyticsPanel>

      {/* 6. Real-Time Status & Financials */}
      <AnalyticsPanel
        title="OR Status Board"
        subtitle="Live updates of each operating room"
      >
        <ORStatusBoard rooms={orStatusRooms} />
      </AnalyticsPanel>
      <FinancialAnalytics
        metrics={financialMetrics}
        trends={financialTrends}
        bySpecialty={specialtyFinancials}
      />

      {/* 7. Predictive & Optimization */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <VolumeForecast data={forecastData} />
        <div className="space-y-6">
          <AnomalyDetection anomalies={anomalyData} />
          <EfficiencyRadar data={efficiencyData} height={300} />
        </div>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <CapacityPrediction predictions={capacityPredictData} />
        <SchedulingOptimization suggestions={schedulingSuggestions} />
      </div>

      {/* 8. Deeper Analysis */}
      <ResourceOptimizationMatrix resources={resourceOptData} />
      <CrossDepartmentAnalysis data={crossDeptData} />
      <BlockTimeOptimization data={blockTimeData} />
      <SurgeonPerformanceAnalysis data={surgeonPerfData} />
    </div>
  );
}
