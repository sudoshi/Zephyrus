import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';

const ProcessStatisticsCards = ({ statistics }) => {
  if (!statistics) return null;

  return (
    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <Card>
        <CardHeader>
          <CardTitle>Total Patients</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-2xl font-bold">{statistics.totalPatients}</p>
        </CardContent>
      </Card>
      
      <Card>
        <CardHeader>
          <CardTitle>Avg Duration</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-2xl font-bold">{statistics.averageDuration} mins</p>
        </CardContent>
      </Card>
      
      <Card>
        <CardHeader>
          <CardTitle>Urgent Cases</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-2xl font-bold text-red-500">{statistics.urgentCases}</p>
        </CardContent>
      </Card>
      
      <Card>
        <CardHeader>
          <CardTitle>Delayed Cases</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-2xl font-bold text-yellow-500">{statistics.delayedCases}</p>
        </CardContent>
      </Card>
    </div>
  );
};

export default ProcessStatisticsCards;
