import React from "react";
import { Icon } from "@iconify/react";

const TrendChip = ({ change, changeType, trendType, position = "top", variant = "light" }) => {
  const getColorClass = () => {
    switch (changeType) {
      case "positive":
        return variant === "light" 
          ? "bg-healthcare-success bg-opacity-10 text-healthcare-success dark:bg-healthcare-success-dark dark:bg-opacity-20 dark:text-healthcare-success-dark"
          : "bg-healthcare-success text-white dark:bg-healthcare-success-dark";
      case "negative":
        return variant === "light"
          ? "bg-healthcare-critical bg-opacity-10 text-healthcare-critical dark:bg-healthcare-critical-dark dark:bg-opacity-20 dark:text-healthcare-critical-dark"
          : "bg-healthcare-critical text-white dark:bg-healthcare-critical-dark";
      default:
        return variant === "light"
          ? "bg-healthcare-warning bg-opacity-10 text-healthcare-warning dark:bg-healthcare-warning-dark dark:bg-opacity-20 dark:text-healthcare-warning-dark"
          : "bg-healthcare-warning text-white dark:bg-healthcare-warning-dark";
    }
  };

  const getIcon = () => {
    switch (trendType) {
      case "up":
        return "solar:arrow-right-up-linear";
      case "down":
        return "solar:arrow-right-down-linear";
      default:
        return "solar:arrow-right-linear";
    }
  };

  return (
    <div
      className={`
        absolute right-4 px-2 py-1 rounded-sm text-xs font-medium
        ${position === "top" ? "top-4" : "bottom-4"}
        ${getColorClass()}
        flex items-center gap-1
      `}
    >
      <Icon icon={getIcon()} width={12} height={12} />
      {change}
    </div>
  );
};

export default function TrendCard({
  title,
  value,
  change,
  changeType = "neutral",
  trendType = "neutral",
  chipPosition = "top",
  chipVariant = "light",
  className = "",
}) {
  return (
    <div className={`relative border border-healthcare-border dark:border-healthcare-border-dark shadow-blue-light dark:shadow-blue-dark rounded-lg bg-white dark:bg-gray-800 ${className}`}>
      <div className="flex p-4">
        <div className="flex flex-col gap-y-2">
          <dt className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{title}</dt>
          <dd className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</dd>
        </div>
        <TrendChip
          change={change}
          changeType={changeType}
          trendType={trendType}
          position={chipPosition}
          variant={chipVariant}
        />
      </div>
    </div>
  );
}
