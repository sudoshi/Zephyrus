import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import * as XLSX from 'xlsx';
import { groupBy } from 'lodash';

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const RoomsRunningChart = ({ data, title }) => {
  // Convert time in minutes to display format (HH:MM)
  const formatTime = (minutes) => {
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}`;
  };

  // Add reference lines for standard times
  const renderReferenceLines = () => {
    const times = [450, 930, 1050]; // 7:30, 15:30, 17:30
    return times.map((time, i) => (
      <line
        key={i}
        x1={time}
        y1={0}
        x2={time}
        y2="100%"
        stroke="#000"
        strokeWidth={1}
        strokeDasharray="3 3"
      />
    ));
  };

  return (
    <div className="h-96">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart
          data={data}
          margin={{ top: 20, right: 30, left: 20, bottom: 20 }}
        >
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis
            dataKey="time"
            tickFormatter={formatTime}
            domain={[0, 1440]}
            type="number"
            ticks={[0, 240, 480, 720, 960, 1200, 1440]}
          />
          <YAxis domain={[0, 'auto']} />
          <Tooltip
            labelFormatter={formatTime}
            formatter={(value) => [value.toFixed(1), '']}
          />
          <Legend />
          <Line
            type="monotone"
            dataKey="avg"
            name="Avg Total Occupied"
            stroke="#ef4444"
            dot={false}
            strokeWidth={2}
          />
          <Line
            type="monotone"
            dataKey="avgPlusStd"
            name="Avg+ StDev Total Occupied"
            stroke="#f97316"
            dot={false}
            strokeWidth={2}
          />
          <Line
            type="monotone"
            dataKey="max"
            name="Max. Total Occupied"
            stroke="#22c55e"
            dot={false}
            strokeWidth={2}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
};

const RoomsRunningDashboard = () => {
  const [utilData, setUtilData] = useState({
    weekdays: [],
    monday: [],
    tuesday: [],
    wednesday: [],
    thursday: [],
    friday: [],
    weekend: []
  });

  useEffect(() => {
    const loadData = async () => {
      const response = await window.fs.readFile('OR Schedule Extract   v2 data.xlsx');
      const workbook = XLSX.read(response, { cellDates: true, cellNF: true });
      const data = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {raw: true});

      // Filter for MARH OR and valid cases
      const validData = data.filter(row => 
        row.Location === 'MARH OR' && 
        row.SchedStatus !== 'Canceled' &&
        row.Sched_Start_Time
      );

      // Process each case to get room utilization
      function processRoomUtilization(cases) {
        const timePoints = Array.from({length: 24*4}, (_, i) => i * 15);
        return timePoints.map(minutes => {
          const activeRooms = cases.filter(c => {
            const startTime = new Date(c.Sched_Start_Time);
            const startMinutes = startTime.getHours() * 60 + startTime.getMinutes();
            const duration = c.Sched_Duration + (c.Setup_Offset || 0) + (c.Cleanup_Offset || 0);
            const endMinutes = startMinutes + duration;
            return minutes >= startMinutes && minutes <= endMinutes;
          });
          return activeRooms.length;
        });
      }

      // Group cases by day of week
      const casesByDay = groupBy(validData, row => new Date(row.Sched_Start_Time).getDay());

      // Process weekday data
      const weekdaysCases = Object.entries(casesByDay)
        .filter(([day]) => day > 0 && day < 6)
        .flatMap(([_, cases]) => cases);

      const weekdayUtil = processRoomUtilization(weekdaysCases);
      
      // Process individual days
      const dayUtils = {};
      for (let day = 1; day <= 5; day++) {
        // Group cases by week for this day
        const dayCases = casesByDay[day] || [];
        const casesByWeek = groupBy(dayCases, row => {
          const date = new Date(row.Sched_Start_Time);
          return `${date.getFullYear()}-${date.getMonth()}-${Math.floor(date.getDate() / 7)}`;
        });
        dayUtils[day] = Object.values(casesByWeek).map(weekCases => 
          processRoomUtilization(weekCases)
        );
      }

      // Process weekend data
      const weekendCases = [...(casesByDay['0'] || []), ...(casesByDay['6'] || [])];
      const weekendUtil = processRoomUtilization(weekendCases);

      // Calculate statistics for each time series
      const timePoints = Array.from({length: 24*4}, (_, i) => ({ time: i * 15 }));

      const calcStats = (utils) => {
        return timePoints.map((point, i) => {
          // Get all values for this time point
          const values = Array.isArray(utils[0]) 
            ? utils.map(u => u[i])
            : [utils[i]];
          
          // Remove undefined/null values
          const validValues = values.filter(v => v !== undefined && v !== null);
          
          if (validValues.length === 0) {
            return {
              ...point,
              avg: 0,
              avgPlusStd: 0,
              max: 0
            };
          }

          const avg = validValues.reduce((a, b) => a + b, 0) / validValues.length;
          
          // Calculate standard deviation
          const squareDiffs = validValues.map(value => {
            const diff = value - avg;
            return diff * diff;
          });
          const avgSquareDiff = squareDiffs.reduce((a, b) => a + b, 0) / validValues.length;
          const stdDev = Math.sqrt(avgSquareDiff);

          return {
            ...point,
            avg,
            avgPlusStd: avg + stdDev,
            max: Math.max(...validValues)
          };
        });
      };

      setUtilData({
        weekdays: calcStats(weekdayUtil),
        monday: calcStats(dayUtils[1]),
        tuesday: calcStats(dayUtils[2]),
        wednesday: calcStats(dayUtils[3]),
        thursday: calcStats(dayUtils[4]),
        friday: calcStats(dayUtils[5]),
        weekend: calcStats(weekendUtil)
      });
    };

    loadData();
  }, []);

  return (
    <div className="space-y-8">
      <Card>
        <CardHeader>
          <CardTitle>All Weekdays Combined</CardTitle>
        </CardHeader>
        <CardContent>
          <RoomsRunningChart data={utilData.weekdays} />
        </CardContent>
      </Card>

      {['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].map((day) => (
        <Card key={day}>
          <CardHeader>
            <CardTitle>{day.charAt(0).toUpperCase() + day.slice(1)}</CardTitle>
          </CardHeader>
          <CardContent>
            <RoomsRunningChart data={utilData[day]} />
          </CardContent>
        </Card>
      ))}

      <Card>
        <CardHeader>
          <CardTitle>Weekends (Saturday & Sunday)</CardTitle>
        </CardHeader>
        <CardContent>
          <RoomsRunningChart data={utilData.weekend} />
        </CardContent>
      </Card>
    </div>
  );
};

export default RoomsRunningDashboard;
