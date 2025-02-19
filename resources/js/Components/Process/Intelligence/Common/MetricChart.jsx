import React from 'react';
import {
  ResponsiveContainer,
  CartesianGrid,
  XAxis,
  YAxis,
  Tooltip,
  Legend
} from 'recharts';

const MetricChart = ({
  title,
  icon: Icon,
  height = "64",
  children,
  className = "",
  yAxisLabel,
  yAxisDomain,
  xAxisDataKey,
  xAxisInterval,
  xAxisAngle,
  xAxisHeight,
  tooltipContent,
  legendContent
}) => {
  return (
    <div className={`healthcare-card ${className}`}>
      {(title || Icon) && (
        <h3 className="font-bold mb-6 flex items-center gap-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {Icon && <Icon className="h-5 w-5" />}
          {title}
        </h3>
      )}
      <div className={`h-${height} bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-4`}>
        <ResponsiveContainer width="100%" height="100%">
          {React.cloneElement(
            // The chart component (BarChart, LineChart, etc.) should be passed as children
            React.Children.only(children),
            {},
            [
              <CartesianGrid key="grid" strokeDasharray="3 3" />,
              <XAxis 
                key="xAxis"
                dataKey={xAxisDataKey}
                interval={xAxisInterval}
                angle={xAxisAngle}
                height={xAxisHeight}
              />,
              <YAxis 
                key="yAxis"
                domain={yAxisDomain}
                label={yAxisLabel ? {
                  value: yAxisLabel,
                  angle: -90,
                  position: 'insideLeft'
                } : undefined}
              />,
              <Tooltip 
                key="tooltip"
                content={tooltipContent}
              />,
              legendContent && <Legend key="legend" content={legendContent} />,
              // Pass through any other chart-specific elements (Bar, Line, etc.)
              ...React.Children.toArray(children.props.children)
            ]
          )}
        </ResponsiveContainer>
      </div>
    </div>
  );
};

// Helper components for consistent styling
export const ChartTooltip = ({ active, payload, label, formatter }) => {
  if (!active || !payload || !payload.length) return null;

  return (
    <div className="healthcare-panel border border-healthcare-border dark:border-healthcare-border-dark">
      <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{label}</p>
      {formatter ? (
        formatter(payload, label)
      ) : (
        payload.map((entry, index) => (
          <p key={index} className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {entry.name}: {entry.value}
          </p>
        ))
      )}
    </div>
  );
};

export const ChartLegend = ({ payload }) => (
  <ul className="flex gap-6 justify-center mt-4">
    {payload.map((entry, index) => (
      <li key={index} className="flex items-center gap-2">
        <span
          className="w-3 h-3 rounded-sm"
          style={{ backgroundColor: `var(--healthcare-${entry.color})` }}
        />
        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {entry.value}
        </span>
      </li>
    ))}
  </ul>
);

export default MetricChart;
