import React, { useState, useEffect } from 'react';
import { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import { Icon } from '@iconify/react';
import PropTypes from 'prop-types';
// Import the shared chart theme utility
import { getChartTheme } from '@/utils/chartTheme';

const TimelineSlider = ({ 
  onChange,
  className = ''
}) => {
  const { isDarkMode } = useDarkMode();
  
  // Create a date for today
  const today = new Date();
  
  // Create a date for today at 06:00
  const defaultStartTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 6, 0, 0);
  
  // Create a date for today at 20:00
  const defaultEndTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 20, 0, 0);
  
  // Full day range for the slider (00:00 to 24:00)
  const startTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 0, 0, 0);
  const endTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 24, 0, 0);
  
  const [selectedRange, setSelectedRange] = useState([defaultStartTime, defaultEndTime]);
  const [isDragging, setIsDragging] = useState(false);
  const [activeDragger, setActiveDragger] = useState(null);
  
  // Calculate the total time range in milliseconds (24 hours)
  const totalRange = 24 * 60 * 60 * 1000; // 24 hours in milliseconds
  
  // Format time for display using 24hr clock
  const formatTime = (date) => {
    return date.toLocaleTimeString('en-US', { 
      hour: '2-digit', 
      minute: '2-digit',
      hour12: false
    });
  };
  
  // Calculate the position percentage based on time
  const getPositionPercentage = (date) => {
    // Extract hours and minutes and convert to percentage of day
    const hours = date.getHours();
    const minutes = date.getMinutes();
    return ((hours * 60 + minutes) / (24 * 60)) * 100;
  };
  
  // Calculate time from position percentage
  const getTimeFromPercentage = (percentage) => {
    // Convert percentage to minutes since midnight
    const minutesSinceMidnight = (percentage / 100) * (24 * 60);
    const hours = Math.floor(minutesSinceMidnight / 60);
    const minutes = Math.floor(minutesSinceMidnight % 60);
    
    // Create a new date with the calculated hours and minutes
    const result = new Date(today.getFullYear(), today.getMonth(), today.getDate(), hours, minutes, 0);
    return result;
  };
  
  // Handle slider thumb drag
  const handleMouseDown = (e, index) => {
    e.preventDefault();
    setIsDragging(true);
    setActiveDragger(index);
  };
  
  // Handle mouse move during drag
  const handleMouseMove = (e) => {
    if (!isDragging) return;
    
    const sliderRect = e.currentTarget.getBoundingClientRect();
    const percentage = Math.max(0, Math.min(100, ((e.clientX - sliderRect.left) / sliderRect.width) * 100));
    const newTime = getTimeFromPercentage(percentage);
    
    const newRange = [...selectedRange];
    newRange[activeDragger] = newTime;
    
    // Ensure start time is before end time
    if (activeDragger === 0 && newTime.getTime() < selectedRange[1].getTime()) {
      setSelectedRange([newTime, selectedRange[1]]);
    } else if (activeDragger === 1 && newTime.getTime() > selectedRange[0].getTime()) {
      setSelectedRange([selectedRange[0], newTime]);
    }
  };
  
  // Handle mouse up to end dragging
  const handleMouseUp = () => {
    if (isDragging) {
      setIsDragging(false);
      onChange && onChange(selectedRange);
    }
  };
  
  // Add event listeners for mouse up outside the component
  useEffect(() => {
    const handleGlobalMouseUp = () => {
      if (isDragging) {
        setIsDragging(false);
        onChange && onChange(selectedRange);
      }
    };
    
    document.addEventListener('mouseup', handleGlobalMouseUp);
    return () => {
      document.removeEventListener('mouseup', handleGlobalMouseUp);
    };
  }, [isDragging, onChange, selectedRange]);
  
  // Generate hourly tick marks
  const renderHourlyTicks = () => {
    // Apply chart theme based on dark mode
    const theme = getChartTheme(isDarkMode);
    const ticks = [];
    
    // Create 24 tick marks, one for each hour
    for (let hour = 0; hour <= 24; hour++) {
      const position = (hour / 24) * 100;
      const isMainTick = hour % 4 === 0; // Highlight every 4 hours
      
      ticks.push(
        <div 
          key={hour}
          className={`absolute ${isMainTick ? 'w-1 h-3' : 'w-0.5 h-2'} rounded-sm`}
          style={{ 
            left: `${position}%`, 
            backgroundColor: isMainTick ? theme.colors[0] : 'rgba(255, 255, 255, 0.5)',
            top: isMainTick ? '-1px' : '0px',
            opacity: isMainTick ? 0.9 : 0.7
          }}
          title={`${hour}:00`}
        />
      );
      
      // Add hour labels for main ticks
      if (isMainTick) {
        ticks.push(
          <div 
            key={`label-${hour}`}
            className="absolute text-xs font-medium"
            style={{ 
              left: `${position}%`, 
              bottom: '-16px',
              transform: 'translateX(-50%)',
              color: 'rgba(255, 255, 255, 0.7)'
            }}
          >
            {hour === 24 ? '00:00' : `${hour.toString().padStart(2, '0')}:00`}
          </div>
        );
      }
    }
    
    return ticks;
  };
  
  // Apply chart theme based on dark mode
  const theme = getChartTheme(isDarkMode);
  
  return (
    <div className={`relative ${className} py-0 px-3 flex items-center`} style={{ width: '800px', height: '42px' }}>
      {/* Time labels above slider */}
      <div className="text-xs font-medium absolute top-1 w-full text-gray-700 dark:text-white z-10">
        <div 
          className="absolute" 
          style={{ 
            left: `${getPositionPercentage(selectedRange[0])}%`, 
            transform: 'translateX(-50%)',
            fontWeight: 'bold'
          }}
        >
          {formatTime(selectedRange[0])}
        </div>
        <div 
          className="absolute" 
          style={{ 
            left: `${getPositionPercentage(selectedRange[1])}%`, 
            transform: 'translateX(-50%)',
            fontWeight: 'bold'
          }}
        >
          {formatTime(selectedRange[1])}
        </div>
      </div>
      
      <div 
        className="relative h-8 cursor-pointer w-full mt-6"
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
      >
        {/* Timeline bar */}
        <div className="absolute top-4 w-full h-2 bg-gray-400 dark:bg-gray-700 rounded" style={{ opacity: 0.4 }}>
          {/* Selected range */}
          <div 
            className="absolute h-full rounded"
            style={{
              left: `${getPositionPercentage(selectedRange[0])}%`,
              width: `${getPositionPercentage(selectedRange[1]) - getPositionPercentage(selectedRange[0])}%`,
              backgroundColor: theme.colors[0]
            }}
          />
          
          {/* Hourly tick marks */}
          {renderHourlyTicks()}
        </div>
        
        {/* Start thumb */}
        <div 
          className={`absolute top-2.5 w-4 h-4 bg-white dark:bg-gray-800 rounded-full shadow-lg transform -translate-x-1/2 ${isDragging && activeDragger === 0 ? 'scale-110' : ''}`}
          style={{ 
            left: `${getPositionPercentage(selectedRange[0])}%`,
            borderWidth: '2px',
            borderColor: theme.colors[0]
          }}
          onMouseDown={(e) => handleMouseDown(e, 0)}
        />
        
        {/* End thumb */}
        <div 
          className={`absolute top-2.5 w-4 h-4 bg-white dark:bg-gray-800 rounded-full shadow-lg transform -translate-x-1/2 ${isDragging && activeDragger === 1 ? 'scale-110' : ''}`}
          style={{ 
            left: `${getPositionPercentage(selectedRange[1])}%`,
            borderWidth: '2px',
            borderColor: theme.colors[0]
          }}
          onMouseDown={(e) => handleMouseDown(e, 1)}
        />
      </div>
      

    </div>
  );
};

TimelineSlider.propTypes = {
  onChange: PropTypes.func,
  className: PropTypes.string
};

export default TimelineSlider;
