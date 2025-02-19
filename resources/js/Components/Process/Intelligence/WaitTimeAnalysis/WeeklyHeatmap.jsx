import React, { useMemo } from 'react';
import { format } from 'date-fns';
import StatusTooltip from '../ResourceAnalysis/StatusTooltip';

const WeeklyHeatmap = ({ 
  data,
  minValue = 0,
  maxValue = 1,
  width = 800,
  height = 200,
  className = ''
}) => {
  const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const hours = Array.from({ length: 24 }, (_, i) => i);

  const colorScale = useMemo(() => {
    return (value) => {
      const normalized = (value - minValue) / (maxValue - minValue);
      if (normalized >= 0.8) return 'bg-healthcare-critical/20 text-healthcare-critical';
      if (normalized >= 0.6) return 'bg-healthcare-warning/20 text-healthcare-warning';
      if (normalized >= 0.4) return 'bg-healthcare-info/20 text-healthcare-info';
      return 'bg-healthcare-success/20 text-healthcare-success';
    };
  }, [minValue, maxValue]);

  const getTooltipContent = (day, hour, value) => {
    const time = format(new Date().setHours(hour, 0, 0, 0), 'h:mm a');
    return (
      <div className="space-y-2">
        <div className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {day} at {time}
        </div>
        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Load: {Math.round(value * 100)}%
        </div>
        {value >= 0.8 && (
          <div className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark font-medium">
            Peak Volume Period
          </div>
        )}
        {value <= 0.4 && (
          <div className="text-sm text-healthcare-success dark:text-healthcare-success-dark font-medium">
            Low Volume Period
          </div>
        )}
      </div>
    );
  };

  return (
    <div className={`healthcare-card ${className}`}>
      {/* Legend */}
      <div className="flex items-center justify-end gap-4 mb-4">
        <div className="flex items-center gap-2">
          <div className="w-4 h-4 rounded bg-healthcare-critical/20" />
          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Peak</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-4 h-4 rounded bg-healthcare-warning/20" />
          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">High</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-4 h-4 rounded bg-healthcare-info/20" />
          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Medium</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-4 h-4 rounded bg-healthcare-success/20" />
          <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Low</span>
        </div>
      </div>

      {/* Grid */}
      <div className="relative" style={{ width, height }}>
        {/* Hour Labels */}
        <div className="absolute -left-12 top-8 bottom-0 flex flex-col justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {hours.map(hour => (
            <div key={hour} className="text-right">
              {format(new Date().setHours(hour, 0, 0, 0), 'h a')}
            </div>
          ))}
        </div>

        {/* Day Labels */}
        <div className="absolute left-0 -top-6 right-0 flex justify-between text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {days.map(day => (
            <div key={day} className="w-24 text-center">
              {day.slice(0, 3)}
            </div>
          ))}
        </div>

        {/* Heatmap Grid */}
        <div className="absolute left-0 top-8 right-0 bottom-0 grid grid-cols-7 gap-1">
          {days.map(day => (
            <div key={day} className="grid grid-rows-24 gap-1">
              {hours.map(hour => {
                const value = data[day.toLowerCase()]?.[hour] || 0;
                return (
                  <StatusTooltip
                    key={hour}
                    content={getTooltipContent(day, hour, value)}
                    position="right"
                  >
                    <div
                      className={`rounded-sm ${colorScale(value)} transition-colors duration-200 hover:opacity-80`}
                      role="gridcell"
                      aria-label={`${day} ${hour}:00 - Load: ${Math.round(value * 100)}%`}
                    />
                  </StatusTooltip>
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default WeeklyHeatmap;
