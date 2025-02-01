import React from 'react';

const Calendar = ({ value = new Date(), onChange, renderDayContent, className = '' }) => {
    const getDaysInMonth = (date) => {
        const year = date.getFullYear();
        const month = date.getMonth();
        return new Date(year, month + 1, 0).getDate();
    };

    const getFirstDayOfMonth = (date) => {
        const year = date.getFullYear();
        const month = date.getMonth();
        return new Date(year, month, 1).getDay();
    };

    const daysInMonth = getDaysInMonth(value);
    const firstDayOfMonth = getFirstDayOfMonth(value);
    const days = Array.from({ length: daysInMonth }, (_, i) => i + 1);
    const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    const handleDateClick = (day) => {
        const newDate = new Date(value.getFullYear(), value.getMonth(), day);
        // Set time to midnight to avoid timezone issues
        newDate.setHours(0, 0, 0, 0);
        onChange(newDate);
    };

    const handleMonthChange = (increment) => {
        const newDate = new Date(value);
        newDate.setMonth(newDate.getMonth() + increment);
        // Set time to midnight to avoid timezone issues
        newDate.setHours(0, 0, 0, 0);
        // Reset to first day of month to avoid skipping months
        newDate.setDate(1);
        onChange(newDate);
    };

    const isCurrentMonth = (date) => {
        const now = new Date();
        return date.getMonth() === now.getMonth() && 
               date.getFullYear() === now.getFullYear();
    };

    const isToday = (day) => {
        const now = new Date();
        return day === now.getDate() && isCurrentMonth(value);
    };

    return (
        <div className={`bg-white h-full flex flex-col ${className}`}>
            <div className="flex items-center justify-between px-6 py-4">
                <h2 className="text-lg font-semibold text-gray-900">
                    {value.toLocaleString('default', { month: 'long', year: 'numeric' })}
                </h2>
                <div className="flex space-x-2">
                    <button
                        onClick={() => handleMonthChange(-1)}
                        className="p-2 hover:bg-gray-100 rounded-full"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <button
                        onClick={() => handleMonthChange(1)}
                        className="p-2 hover:bg-gray-100 rounded-full"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>

            <div className="border-t border-gray-200 flex-1 flex flex-col">
                <div className="grid grid-cols-7 gap-px bg-gray-200 text-center text-xs leading-6 text-gray-700">
                    {weekDays.map(day => (
                        <div key={day} className="bg-white py-2 font-semibold">
                            {day}
                        </div>
                    ))}
                </div>

                <div className="flex-1 overflow-y-auto min-h-0">
                    <div className="grid grid-cols-7 gap-px bg-gray-200 min-h-full">
                        {Array(firstDayOfMonth).fill(null).map((_, index) => (
                            <div key={`empty-${index}`} className="bg-white h-[120px]" />
                        ))}
                        
                        {days.map(day => {
                            const date = new Date(value.getFullYear(), value.getMonth(), day);
                            const dayContent = renderDayContent ? renderDayContent(date) : null;

                            return (
                                <div
                                    key={day}
                                    onClick={() => handleDateClick(day)}
                                    className={`bg-white p-2 cursor-pointer hover:bg-gray-50 min-h-[120px] overflow-y-auto ${
                                        isToday(day) ? 'bg-blue-50' : ''
                                    }`}
                                >
                                    <div className={`text-sm ${isToday(day) ? 'font-semibold text-blue-600' : ''}`}>
                                        {day}
                                    </div>
                                    {dayContent}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Calendar;
