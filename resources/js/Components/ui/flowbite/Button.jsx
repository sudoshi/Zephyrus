import React from 'react';
import { Button as FlowbiteButton } from 'flowbite-react';

/**
 * Button component wrapper for Flowbite Button
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Button content
 * @param {string} [props.className] - Additional CSS classes
 * @param {string} [props.color="primary"] - Button color variant
 * @param {boolean} [props.disabled] - Whether the button is disabled
 * @param {boolean} [props.isProcessing] - Whether the button is in a processing state
 * @param {string} [props.processingLabel] - Label to show when processing
 * @param {string} [props.processingSpinner] - Custom spinner when processing
 * @param {React.ReactNode} [props.pill] - Whether the button has pill styling
 * @param {string} [props.size="md"] - Button size
 * @param {string} [props.outline] - Whether the button has outline styling
 * @param {string} [props.gradientDuoTone] - Gradient duo tone styling
 * @param {string} [props.gradientMonochrome] - Gradient monochrome styling
 * @param {string} [props.label] - Button label (alternative to children)
 * @param {Function} [props.onClick] - Click handler
 * @returns {React.ReactElement} Button component
 */
export function Button({
  children,
  className = "",
  color = "primary",
  disabled = false,
  isProcessing = false,
  processingLabel,
  processingSpinner,
  pill = false,
  size = "md",
  outline = false,
  gradientDuoTone,
  gradientMonochrome,
  label,
  onClick,
  ...props
}) {
  // Custom healthcare color classes
  const getCustomColorClasses = () => {
    if (outline) {
      return {
        primary: "text-healthcare-primary-dark border-healthcare-primary-dark hover:bg-healthcare-primary-dark hover:text-white",
        success: "text-healthcare-success-dark border-healthcare-success-dark hover:bg-healthcare-success-dark hover:text-white",
        warning: "text-healthcare-warning-dark border-healthcare-warning-dark hover:bg-healthcare-warning-dark hover:text-white",
        critical: "text-healthcare-critical-dark border-healthcare-critical-dark hover:bg-healthcare-critical-dark hover:text-white",
        purple: "text-healthcare-purple-dark border-healthcare-purple-dark hover:bg-healthcare-purple-dark hover:text-white",
        teal: "text-healthcare-teal-dark border-healthcare-teal-dark hover:bg-healthcare-teal-dark hover:text-white",
      }[color] || "";
    }
    
    return {
      primary: "bg-healthcare-primary-dark hover:bg-healthcare-primary-hover-dark text-white",
      success: "bg-healthcare-success-dark hover:bg-healthcare-success-dark/90 text-white",
      warning: "bg-healthcare-warning-dark hover:bg-healthcare-warning-dark/90 text-white",
      critical: "bg-healthcare-critical-dark hover:bg-healthcare-critical-dark/90 text-white",
      purple: "bg-healthcare-purple-dark hover:bg-healthcare-purple-dark/90 text-white",
      teal: "bg-healthcare-teal-dark hover:bg-healthcare-teal-dark/90 text-white",
    }[color] || "";
  };

  // Map custom colors to Flowbite colors
  const mapColor = () => {
    return {
      primary: "blue",
      success: "green",
      warning: "yellow",
      critical: "red",
      purple: "purple",
      teal: "teal",
    }[color] || color;
  };

  return (
    <FlowbiteButton
      className={`${getCustomColorClasses()} ${className}`}
      color={mapColor()}
      disabled={disabled}
      isProcessing={isProcessing}
      processingLabel={processingLabel}
      processingSpinner={processingSpinner}
      pill={pill}
      size={size}
      outline={outline}
      gradientDuoTone={gradientDuoTone}
      gradientMonochrome={gradientMonochrome}
      onClick={onClick}
      {...props}
    >
      {label || children}
    </FlowbiteButton>
  );
}

/**
 * Button.Group component for grouping buttons
 */
Button.Group = function ButtonGroup({ children, className = "", ...props }) {
  return (
    <div className={`inline-flex rounded-lg shadow-sm ${className}`} role="group" {...props}>
      {children}
    </div>
  );
};
