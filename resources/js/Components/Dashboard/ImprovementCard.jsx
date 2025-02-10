import React from 'react';
import { Link } from '@inertiajs/react';
import PropTypes from 'prop-types';

const ImprovementCard = ({ title, description, icon: Icon, href, count, countLabel }) => {
  return (
    <Link
      href={href}
      className="block bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm rounded-lg overflow-hidden hover:bg-healthcare-surface-secondary hover:shadow-md transition-all duration-300 border border-healthcare-border dark:border-healthcare-border-dark"
    >
      <div className="p-6">
        <div className="flex items-center justify-between mb-4">
          <div className="p-2 bg-healthcare-background-soft dark:bg-healthcare-primary-dark/10 rounded-lg transition-colors duration-300">
            <Icon className="h-6 w-6 text-healthcare-primary dark:text-healthcare-primary-dark transition-colors duration-300" />
          </div>
          {count !== undefined && (
            <div className="text-right">
              <div className="text-2xl font-semibold text-healthcare-primary dark:text-healthcare-primary-dark transition-colors duration-300">
                {count}
              </div>
              <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                {countLabel}
              </div>
            </div>
          )}
        </div>
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2 transition-colors duration-300">
          {title}
        </h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
          {description}
        </p>
      </div>
    </Link>
  );
};

ImprovementCard.propTypes = {
  title: PropTypes.string.isRequired,
  description: PropTypes.string.isRequired,
  icon: PropTypes.elementType.isRequired,
  href: PropTypes.string.isRequired,
  count: PropTypes.number,
  countLabel: PropTypes.string,
};

export default ImprovementCard;
