import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const PrimeTimeDashboard = () => {
  // Data from the PDF
  const utilizationData = [
    { month: 'Jan 23', marhIR: 56.16, marhOR: 73.89, nonPrimeIR: 0.00, nonPrimeOR: 16.43 },
    { month: 'Mar 23', marhIR: 54.39, marhOR: 77.20, nonPrimeIR: 0.00, nonPrimeOR: 17.41 },
    { month: 'May 23', marhIR: 70.33, marhOR: 77.46, nonPrimeIR: 0.00, nonPrimeOR: 17.25 },
    { month: 'Jul 23', marhIR: 65.37, marhOR: 76.78, nonPrimeIR: 0.00, nonPrimeOR: 17.21 },
    { month: 'Sep 23', marhIR: 73.77, marhOR: 66.56, nonPrimeIR: 0.00, nonPrimeOR: 17.18 },
    { month: 'Nov 23', marhIR: 55.08, marhOR: 69.47, nonPrimeIR: 0.00, nonPrimeOR: 17.14 },
    { month: 'Jan 24', marhIR: 69.04, marhOR: 76.78, nonPrimeIR: 0.85, nonPrimeOR: 15.80 },
    { month: 'Mar 24', marhIR: 57.71, marhOR: 75.30, nonPrimeIR: 0.53, nonPrimeOR: 15.52 },
    { month: 'May 24', marhIR: 57.25, marhOR: 68.04, nonPrimeIR: 0.00, nonPrimeOR: 15.50 }
  ];

  const weekdayData = {
    'MARH IR': {
      Monday: { utilization: 94.27, nonPrime: 0.00 },
      Tuesday: { utilization: 41.46, nonPrime: 0.00 },
      Wednesday: { utilization: 36.46, nonPrime: 0.00 },
      Thursday: { utilization: 53.75, nonPrime: 0.00 },
      Friday: { utilization: 49.11, nonPrime: 0.00 }
    },
    'MARH OR': {
      Monday: { utilization: 74.20, nonPrime: 20.77 },
      Tuesday: { utilization: 78.43, nonPrime: 15.04 },
      Wednesday: { utilization: 77.41, nonPrime: 15.17 },
      Thursday: { utilization: 69.92, nonPrime: 20.08 },
      Friday: { utilization: 83.64, nonPrime: 16.26 }
    }
  };

  // Custom tooltip for percentage values
  const CustomTooltip = ({ active, payload, label }) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white p-4 border rounded shadow">
          <p className="text-gray-600">{label}</p>
          {payload.map((entry, index) => (
            <p key={index} style={{ color: entry.color }} className="font-medium">
              {entry.name}: {entry.value.toFixed(2)}%
            </p>
          ))}
        </div>
      );
    }
    return null;
  };

  return (
    <div className="space-y-8">
      <Card>
        <CardHeader>
          <CardTitle>Prime Time Utilization by Location</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={utilizationData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[30, 100]} />
                <Tooltip content={<CustomTooltip />} />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="marhIR" 
                  name="MARH IR"
                  stroke="#2563eb" 
                  strokeWidth={2}
                  dot={{ fill: '#2563eb' }}
                />
                <Line 
                  type="monotone" 
                  dataKey="marhOR" 
                  name="MARH OR"
                  stroke="#16a34a" 
                  strokeWidth={2}
                  dot={{ fill: '#16a34a' }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>% Non-Prime Time Trend</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={utilizationData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[0, 25]} />
                <Tooltip content={<CustomTooltip />} />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="nonPrimeIR" 
                  name="MARH IR"
                  stroke="#2563eb" 
                  strokeWidth={2}
                  dot={{ fill: '#2563eb' }}
                />
                <Line 
                  type="monotone" 
                  dataKey="nonPrimeOR" 
                  name="MARH OR"
                  stroke="#16a34a" 
                  strokeWidth={2}
                  dot={{ fill: '#16a34a' }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Non-Prime Time Percentage by Location</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={utilizationData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[0, 25]} />
                <Tooltip content={<CustomTooltip />} />
                <Legend />
                <Bar 
                  dataKey="nonPrimeIR" 
                  name="MARH IR"
                  fill="#2563eb" 
                />
                <Bar 
                  dataKey="nonPrimeOR" 
                  name="MARH OR"
                  fill="#16a34a" 
                />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Day of Week Utilization Matrix</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  <th className="p-2 border text-left">Location</th>
                  <th className="p-2 border text-left">Metric</th>
                  <th className="p-2 border text-center">Monday</th>
                  <th className="p-2 border text-center">Tuesday</th>
                  <th className="p-2 border text-center">Wednesday</th>
                  <th className="p-2 border text-center">Thursday</th>
                  <th className="p-2 border text-center">Friday</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(weekdayData).map(([location, data]) => (
                  <>
                    <tr key={`${location}-util`}>
                      <td rowSpan="2" className="p-2 border font-medium">{location}</td>
                      <td className="p-2 border">Utilization</td>
                      {Object.values(data).map((dayData, idx) => (
                        <td key={idx} className="p-2 border text-center">
                          {dayData.utilization.toFixed(2)}%
                        </td>
                      ))}
                    </tr>
                    <tr key={`${location}-nonprime`}>
                      <td className="p-2 border">Non-Prime</td>
                      {Object.values(data).map((dayData, idx) => (
                        <td key={idx} className="p-2 border text-center">
                          {dayData.nonPrime.toFixed(2)}%
                        </td>
                      ))}
                    </tr>
                  </>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default PrimeTimeDashboard;
