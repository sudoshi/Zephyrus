import React, { useState } from 'react';
import PropTypes from 'prop-types';
import { format } from 'date-fns';
import { TextInput } from 'flowbite-react';

export function DatePicker({ 
  value,
  onChange,
  minDate,
  maxDate,
}) {
  const formatDate = (date) => {
    if (!date) return '';
    return format(date, 'yyyy-MM-dd');
  };

  const handleChange = (e) => {
    const dateValue = e.target.value;
    if (!dateValue) {
      onChange(null);
      return;
    }
    
    const newDate = new Date(dateValue);
    
    // Check if date is valid
    if (isNaN(newDate.getTime())) {
      return;
    }
    
    // Check min/max constraints
    if (minDate && newDate < minDate) {
      return;
    }
    
    if (maxDate && newDate > maxDate) {
      return;
    }
    
    onChange(newDate);
  };

  return (
    <TextInput
      type="date"
      value={formatDate(value)}
      onChange={handleChange}
      min={minDate ? formatDate(minDate) : undefined}
      max={maxDate ? formatDate(maxDate) : undefined}
    />
  );
}

DatePicker.propTypes = {
  value: PropTypes.instanceOf(Date),
  onChange: PropTypes.func.isRequired,
  minDate: PropTypes.instanceOf(Date),
  maxDate: PropTypes.instanceOf(Date),
};

export default DatePicker;
