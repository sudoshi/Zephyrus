import React from "react";
import { Icon } from "@iconify/react";
import  { useDarkMode } from '@/hooks/useDarkMode.js';
import {
  ResponsiveContainer,
  PieChart,
  Pie,
  Tooltip,
  Cell,
  Label
} from "recharts";

const formatValue = (value) => {
  if (!value) return "";
  return value.toLocaleString("en-US");
};

const CircleChartCard = ({
  title,
  value,
  unit,
  categories,
  changePercentage,
  chartData,
  changeType = "neutral",
  className = "",
  colorScheme = "healthcare"
}) => {
  const [isDarkMode] = useDarkMode();
  // Healthcare-specific color schemes
  const getColor = (index) => {
    const colors = {
      healthcare: [
        "var(--healthcare-primary)", // Primary
        "var(--healthcare-success)", // Success
        "var(--healthcare-warning)", // Warning
        "var(--healthcare-critical)", // Critical
      ],
      metrics: [
        "var(--healthcare-info)", // Info
        "var(--healthcare-accent)", // Accent
        "var(--healthcare-primary-hover)", // Primary hover
        "var(--healthcare-info-dark)", // Info dark
      ],
    };

    return colors[colorScheme][index % colors[colorScheme].length];
  };

  const getChangeTypeIcon = () => {
    switch (changeType) {
      case "positive":
        return "solar:arrow-right-up-linear";
      case "negative":
        return "solar:arrow-right-down-linear";
      default:
        return "solar:arrow-right-linear";
    }
  };

  const getChangeTypeColor = () => {
    switch (changeType) {
      case "positive":
        return "text-green-500 dark:text-green-400";
      case "negative":
        return "text-red-500 dark:text-red-400";
      default:
        return "text-gray-500 dark:text-gray-400";
    }
  };

  return (
    <div className={`relative border border-healthcare-border dark:border-healthcare-border-dark shadow-blue-light dark:shadow-blue-dark min-h-[340px] rounded-lg bg-white dark:bg-gray-800 ${className}`}>
      <div className="flex flex-col gap-y-2 p-4 pb-0">
        <div className="flex items-center justify-between gap-x-2">
          <dt>
            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">
              {title}
            </h3>
          </dt>
          <div className="flex items-center justify-end gap-x-2">
            {/* Time Range Selector */}
            <select className="min-h-7 h-7 px-2 text-xs rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <option value="per-day">Per Day</option>
              <option value="per-week">Per Week</option>
              <option value="per-month">Per Month</option>
            </select>
            
            {/* Menu Button */}
            <button className="p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800">
              <Icon
                icon="solar:menu-dots-bold"
                className="w-4 h-4 text-gray-500 dark:text-gray-400"
              />
            </button>
          </div>
        </div>
        <dd className="flex items-baseline gap-x-1">
          <span className="text-3xl font-semibold text-gray-900 dark:text-gray-100">
            {value}
          </span>
          {unit && (
            <span className="text-base font-medium text-gray-600 dark:text-gray-300">
              {unit}
            </span>
          )}
        </dd>
      </div>

      <ResponsiveContainer height={200} width="100%">
        <PieChart margin={{ top: 0, right: 0, left: 0, bottom: 0 }}>
          <Tooltip
            content={({ label, payload }) => (
              <div className="min-w-[120px] rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark p-2 text-xs shadow-blue-light dark:shadow-blue-dark border border-healthcare-border dark:border-healthcare-border-dark">
                <span className="font-medium text-gray-900 dark:text-gray-100">
                  {label}
                </span>
                {payload?.map((p, index) => {
                  const name = p.name;
                  const value = p.value;
                  const category =
                    categories.find((c) => c.toLowerCase() === name) ?? name;

                  return (
                    <div
                      key={`${index}-${name}`}
                      className="flex items-center gap-x-2 mt-1"
                    >
                      <div
                        className="h-2 w-2 rounded-full"
                        style={{ backgroundColor: getColor(index) }}
                      />
                      <div className="flex w-full items-center justify-between">
                        <span className="text-gray-600 dark:text-gray-300">
                          {category}
                        </span>
                        <span className="font-mono font-medium text-gray-900 dark:text-gray-100">
                          {formatValue(value)}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
            cursor={false}
          />
          <Pie
            data={chartData}
            dataKey="value"
            nameKey="name"
            innerRadius="68%"
            outerRadius="100%"
            paddingAngle={-20}
            cornerRadius={12}
            strokeWidth={0}
          >
            {chartData.map((_, index) => (
              <Cell key={`cell-${index}`} fill={getColor(index)} />
            ))}
            <Label
              content={({ viewBox }) => {
                if (viewBox && "cx" in viewBox && "cy" in viewBox) {
                  return (
                    <>
                      <Icon
                        className={`${getChangeTypeColor()} [&>path]:stroke-2`}
                        icon={getChangeTypeIcon()}
                        width={16}
                        height={16}
                        x={viewBox.cx - 40}
                        y={viewBox.cy - (changeType === "positive" ? 8 : changeType === "negative" ? 6 : 0)}
                      />
                      <>
                        <circle
                          cx={viewBox.cx + 10}
                          cy={viewBox.cy}
                          r={24}
                          fill="#1f2937"
                          opacity={0.9}
                        />
                        <text
                          x={viewBox.cx + 10}
                          y={viewBox.cy}
                          textAnchor="middle"
                          dominantBaseline="central"
                          style={{ 
                            fontSize: '1.25rem',
                            fontWeight: 600,
                            fill: '#ffffff'
                          }}
                        >
                          {changePercentage}%
                        </text>
                      </>
                    </>
                  );
                }
                return null;
              }}
              position="center"
            />
          </Pie>
        </PieChart>
      </ResponsiveContainer>

      <div className="flex w-full flex-wrap justify-center gap-4 px-4 pb-4 text-xs text-gray-600 dark:text-gray-300">
        {categories.map((category, index) => (
          <div key={index} className="flex items-center gap-2">
            <span
              className="h-2 w-2 rounded-full"
              style={{ backgroundColor: getColor(index) }}
            />
            <span className="capitalize">{category}</span>
          </div>
        ))}
      </div>
    </div>
  );
};

export default CircleChartCard;
