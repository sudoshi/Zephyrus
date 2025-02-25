import React from 'react';
import { Card as FlowbiteCard } from 'flowbite-react';

/**
 * Card component wrapper for Flowbite Card
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Card content
 * @param {string} [props.title] - Card title
 * @param {string} [props.className] - Additional CSS classes
 * @param {React.ReactNode} [props.footer] - Card footer content
 * @param {React.ReactNode} [props.header] - Custom header content (overrides title)
 * @param {boolean} [props.horizontal] - Whether the card should be horizontal
 * @param {string} [props.imgAlt] - Alt text for the image
 * @param {string} [props.imgSrc] - Source URL for the image
 * @returns {React.ReactElement} Card component
 */
export function Card({ 
  children, 
  title, 
  className = "", 
  footer,
  header,
  horizontal,
  imgAlt,
  imgSrc,
  ...props 
}) {
  return (
    <FlowbiteCard
      className={`bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 transition-colors duration-200 ${className}`}
      horizontal={horizontal}
      imgAlt={imgAlt}
      imgSrc={imgSrc}
      {...props}
    >
      {header ? (
        header
      ) : title ? (
        <h5 className="text-xl font-bold tracking-tight text-gray-900 dark:text-white">
          {title}
        </h5>
      ) : null}
      
      {children}
      
      {footer && (
        <div className="mt-auto pt-4 border-t border-gray-200 dark:border-gray-700">
          {footer}
        </div>
      )}
    </FlowbiteCard>
  );
}

/**
 * Card.Header component for custom card headers
 */
Card.Header = function CardHeader({ children, className = "", ...props }) {
  return (
    <div className={`mb-4 ${className}`} {...props}>
      {children}
    </div>
  );
};

/**
 * Card.Body component for card content
 */
Card.Body = function CardBody({ children, className = "", ...props }) {
  return (
    <div className={`flex-grow ${className}`} {...props}>
      {children}
    </div>
  );
};

/**
 * Card.Footer component for card footers
 */
Card.Footer = function CardFooter({ children, className = "", ...props }) {
  return (
    <div className={`mt-auto pt-4 border-t border-gray-700 ${className}`} {...props}>
      {children}
    </div>
  );
};
