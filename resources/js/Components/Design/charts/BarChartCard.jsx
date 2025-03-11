import React from "react";
import { Icon } from "@iconify/react";
import { useDarkMode } from '@/hooks/useDarkMode';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer
} from "recharts";

const formatWeekday = (weekday) => {
  const day = {
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
    Sun: 0,
  }[weekday] ?? 0;

  return new Intl.DateTimeFormat("en-US", { weekday: "long" }).format(
    new Date(2024, 0, day)
  );
};

const BarChartCard = ({
  title,
  categories,
  chartData,
  className = "",
}) => {
  const [isDarkMode] = useDarkMode();

  const getBarColor = (index) => {
    // Map each category to a specific color based on its meaning in the care journey
    const categoryColors = {
      hospital: {
        color: "var(--healthcare-primary)",
        darkColor: "var(--healthcare-primary-dark)",
        label: "Hospital Care"
      },
      transition: {
        color: "var(--healthcare-purple)",
        darkColor: "var(--healthcare-purple-dark)",
        label: "Care Transition"
      },
      homesetup: {
        color: "var(--healthcare-orange)",
        darkColor: "var(--healthcare-orange-dark)",
        label: "Home Setup"
      },
      active: {
        color: "var(--healthcare-success)",
        darkColor: "var(--healthcare-success-dark)",
        label: "Active Care"
      },
      monitoring: {
        color: "var(--healthcare-teal)",
        darkColor: "var(--healthcare-teal-dark)",
        label: "Monitoring"
      }
    };

    // Get the category name from the index and normalize it
    const category = categories[index]?.toLowerCase().replace(/\s+/g, '');
    const colorSet = categoryColors[category];
    return isDarkMode ? colorSet?.darkColor : colorSet?.color;
  };

  return (
    <div className={`relative border border-healthcare-border dark:border-healthcare-border-dark shadow-blue-light dark:shadow-blue-dark rounded-lg bg-white dark:bg-gray-800 ${className}`}>
      <div className="flex flex-col gap-y-4 p-4">
        <dt>
          <h3 className="text-sm font-medium text-gray-600 dark:text-gray-300">
            {title}
          </h3>
        </dt>
        <dd className="flex w-full justify-end gap-4 text-xs text-gray-600 dark:text-gray-300">
          {categories.map((category, index) => (
            <div key={index} className="flex items-center gap-2">
              <span
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: getBarColor(index) }}
              />
              <span className="capitalize">{category}</span>
            </div>
          ))}
        </dd>
      </div>

      <div className="h-[200px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={chartData}
            margin={{
              top: 20,
              right: 14,
              left: -8,
              bottom: 5,
            }}
          >
            <XAxis
              dataKey="weekday"
              stroke="currentColor"
              strokeOpacity={0.25}
              fontSize="12px"
              tickLine={false}
              axisLine={true}
              className="text-gray-600 dark:text-gray-400"
            />
            <YAxis
              stroke="currentColor"
              fontSize="12px"
              tickLine={false}
              axisLine={false}
              className="text-gray-600 dark:text-gray-400"
            />
            <Tooltip
              content={({ label, payload }) => (
                <div className="min-w-[120px] rounded-lg bg-white dark:bg-gray-800 p-2 text-xs shadow-lg border border-gray-200 dark:border-gray-700">
                  <div className="flex flex-col gap-y-1">
                    <span className="font-medium text-gray-900 dark:text-gray-100">
                      {formatWeekday(label)}
                    </span>
                    {payload?.map((p, index) => {
                      const name = p.name;
                      const value = p.value;
                      const category = categories.find(
                        (c) => c.toLowerCase() === name
                      ) ?? name;

                      return (
                        <div
                          key={`${index}-${name}`}
                          className="flex items-center gap-x-2"
                        >
                          <div
                            className="h-2 w-2 rounded-full"
                            style={{ backgroundColor: getBarColor(index) }}
                          />
                          <div className="flex w-full items-center justify-between gap-x-2">
                            <span className="text-gray-600 dark:text-gray-300">
                              {category}
                            </span>
                            <span className="font-mono font-medium text-gray-900 dark:text-gray-100">
                              {value}
                            </span>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
              cursor={false}
            />
            {categories.map((category, index) => (
              <Bar
                key={`${category}-${index}`}
                dataKey={category.toLowerCase()}
                fill={getBarColor(index)}
                radius={index === categories.length - 1 ? [4, 4, 0, 0] : 0}
                stackId="bars"
              />
            ))}
          </BarChart>
        </ResponsiveContainer>
      </div>

      {/* Time Range Selector */}
      <div className="flex gap-2 p-4 border-t border-gray-200 dark:border-gray-700">
        <button className="px-3 py-1 text-xs font-medium rounded-md bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
          7 days
        </button>
        <button className="px-3 py-1 text-xs font-medium rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-300">
          14 days
        </button>
        <button className="px-3 py-1 text-xs font-medium rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-300">
          30 days
        </button>
      </div>

      {/* Menu Button */}
      <button className="absolute right-2 top-2 p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800">
        <Icon
          icon="solar:menu-dots-bold"
          className="w-4 h-4 text-gray-500 dark:text-gray-400"
        />
      </button>
    </div>
  );
};

export default BarChartCard;
