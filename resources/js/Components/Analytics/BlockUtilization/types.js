import PropTypes from 'prop-types';

export const ServiceMetricsPropType = PropTypes.shape({
  service_id: PropTypes.number,
  service_name: PropTypes.string.isRequired,
  numof_cases: PropTypes.number,
  before_block_start: PropTypes.number,
  in_block: PropTypes.number,
  overusage: PropTypes.number,
  out_of_block: PropTypes.number,
  after_block_finish: PropTypes.number,
  non_prime_percentage: PropTypes.number,
  block_time: PropTypes.number,
  in_block_utilization: PropTypes.number,
  total_block_utilization: PropTypes.number
});

export const TrendDataPropType = PropTypes.shape({
  utilization: PropTypes.arrayOf(PropTypes.shape({
    month: PropTypes.string.isRequired,
    value: PropTypes.number.isRequired
  })),
  nonPrimeTime: PropTypes.arrayOf(PropTypes.shape({
    month: PropTypes.string.isRequired,
    value: PropTypes.number.isRequired
  })),
  comparative: PropTypes.shape({
    current: PropTypes.shape({
      nonPrimeTime: PropTypes.number,
      primeTimeUtil: PropTypes.number
    }),
    previous: PropTypes.shape({
      nonPrimeTime: PropTypes.number,
      primeTimeUtil: PropTypes.number
    })
  })
});

export const DayOfWeekDataPropType = PropTypes.objectOf(PropTypes.shape({
  Monday: PropTypes.number,
  Tuesday: PropTypes.number,
  Wednesday: PropTypes.number,
  Thursday: PropTypes.number,
  Friday: PropTypes.number,
  total: PropTypes.number
}));

export const ProviderMetricsPropType = PropTypes.objectOf(PropTypes.shape({
  service: PropTypes.string.isRequired,
  numof_cases: PropTypes.number,
  before_block_start: PropTypes.number,
  in_block: PropTypes.number,
  overusage: PropTypes.number,
  out_of_block: PropTypes.number,
  after_block_finish: PropTypes.number,
  non_prime_percentage: PropTypes.number,
  block_time: PropTypes.number,
  in_block_utilization: PropTypes.number,
  total_block_utilization: PropTypes.number
}));
