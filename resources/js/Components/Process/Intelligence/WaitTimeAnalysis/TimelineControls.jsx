import React, { useState, useCallback } from 'react';
import { ZoomIn, ZoomOut, ChevronLeft, ChevronRight, Calendar } from 'lucide-react';
import { format, subDays, addDays } from 'date-fns';

const TimelineControls = ({
  onZoomChange,
  onRangeChange,
  onDrillDown,
  minDate,
  maxDate,
  currentRange = { start: new Date(), end: new Date() }
}) => {
  const [zoomLevel, setZoomLevel] = useState(1);
  const [selectedRange, setSelectedRange] = useState(currentRange);

  const handleZoomIn = useCallback(() => {
    const newZoom = Math.min(zoomLevel + 0.25, 2);
    setZoomLevel(newZoom);
    onZoomChange?.(newZoom);
  }, [zoomLevel, onZoomChange]);

  const handleZoomOut = useCallback(() => {
    const newZoom = Math.max(zoomLevel - 0.25, 0.5);
    setZoomLevel(newZoom);
    onZoomChange?.(newZoom);
  }, [zoomLevel, onZoomChange]);

  const handlePrevious = useCallback(() => {
    const newStart = subDays(selectedRange.start, 1);
    const newEnd = subDays(selectedRange.end, 1);
    if (newStart >= minDate) {
      setSelectedRange({ start: newStart, end: newEnd });
      onRangeChange?.({ start: newStart, end: newEnd });
    }
  }, [selectedRange, minDate, onRangeChange]);

  const handleNext = useCallback(() => {
    const newStart = addDays(selectedRange.start, 1);
    const newEnd = addDays(selectedRange.end, 1);
    if (newEnd <= maxDate) {
      setSelectedRange({ start: newStart, end: newEnd });
      onRangeChange?.({ start: newStart, end: newEnd });
    }
  }, [selectedRange, maxDate, onRangeChange]);

  const handleDateSelect = useCallback((date) => {
    const newRange = {
      start: date,
      end: addDays(date, 1)
    };
    setSelectedRange(newRange);
    onRangeChange?.(newRange);
  }, [onRangeChange]);

  return (
    <div className="flex items-center justify-between p-4 healthcare-card">
      {/* Zoom Controls */}
      <div className="flex items-center gap-2">
        <button
          onClick={handleZoomOut}
          disabled={zoomLevel <= 0.5}
          className="p-2 rounded-full bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark disabled:opacity-50 disabled:cursor-not-allowed healthcare-transition"
          aria-label="Zoom out"
        >
          <ZoomOut className="h-5 w-5" />
        </button>
        <button
          onClick={handleZoomIn}
          disabled={zoomLevel >= 2}
          className="p-2 rounded-full bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark disabled:opacity-50 disabled:cursor-not-allowed healthcare-transition"
          aria-label="Zoom in"
        >
          <ZoomIn className="h-5 w-5" />
        </button>
      </div>

      {/* Navigation Controls */}
      <div className="flex items-center gap-4">
        <button
          onClick={handlePrevious}
          disabled={selectedRange.start <= minDate}
          className="p-2 rounded-full bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark disabled:opacity-50 disabled:cursor-not-allowed healthcare-transition"
          aria-label="Previous day"
        >
          <ChevronLeft className="h-5 w-5" />
        </button>
        
        <button
          onClick={() => onDrillDown?.(selectedRange)}
          className="flex items-center gap-2 px-4 py-2 rounded-md bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark healthcare-transition"
        >
          <Calendar className="h-4 w-4" />
          <span className="text-sm font-medium">
            {format(selectedRange.start, 'MMM d, yyyy')}
          </span>
        </button>

        <button
          onClick={handleNext}
          disabled={selectedRange.end >= maxDate}
          className="p-2 rounded-full bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark disabled:opacity-50 disabled:cursor-not-allowed healthcare-transition"
          aria-label="Next day"
        >
          <ChevronRight className="h-5 w-5" />
        </button>
      </div>

      {/* Zoom Level Indicator */}
      <div className="px-3 py-1 rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-sm">
        {Math.round(zoomLevel * 100)}%
      </div>
    </div>
  );
};

export default TimelineControls;
