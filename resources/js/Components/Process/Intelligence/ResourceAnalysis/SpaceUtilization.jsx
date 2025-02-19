import React from 'react';
import { AreaChart, Area, LineChart, Line } from 'recharts';
import { DoorOpen, Clock, RotateCcw, Zap } from 'lucide-react';
import MetricChart from '../Common/MetricChart';

const SpaceUtilization = ({ resourceData, predictions }) => {
  const getOccupancyHeatmap = () => {
    const hours = Array.from({ length: 24 }, (_, i) => 
      String(i).padStart(2, '0') + ':00'
    );

    return hours.map(hour => {
      const peakHour = predictions.patternAnalysis.peakHours.find(
        peak => peak.start <= hour && peak.end >= hour
      );

      return {
        hour,
        occupancy: Math.round((peakHour?.severity || 0.5) * 100),
        turnover: Math.round(Math.random() * 5) // Simulated turnover count
      };
    });
  };

  const getRoomStatus = () => {
    const total = resourceData.space.current.rooms.capacity;
    const statuses = ['Occupied', 'Turnover', 'Available', 'Maintenance'];
    const rooms = Array.from({ length: total }, (_, i) => ({
      id: `R${String(i + 1).padStart(3, '0')}`,
      status: statuses[Math.floor(Math.random() * statuses.length)],
      duration: Math.round(Math.random() * 120), // Minutes
      nextAction: Math.round(Math.random() * 30) // Minutes until next action
    }));

    return {
      rooms,
      summary: {
        occupied: rooms.filter(r => r.status === 'Occupied').length,
        turnover: rooms.filter(r => r.status === 'Turnover').length,
        available: rooms.filter(r => r.status === 'Available').length,
        maintenance: rooms.filter(r => r.status === 'Maintenance').length
      }
    };
  };

  const getTurnoverMetrics = () => {
    return [
      { time: '08:00', duration: 35 },
      { time: '09:00', duration: 42 },
      { time: '10:00', duration: 28 },
      { time: '11:00', duration: 45 },
      { time: '12:00', duration: 38 },
      { time: '13:00', duration: 32 },
      { time: '14:00', duration: 40 }
    ];
  };

  const heatmapData = getOccupancyHeatmap();
  const roomStatus = getRoomStatus();
  const turnoverMetrics = getTurnoverMetrics();

  const getStatusColor = (status) => {
    switch (status) {
      case 'Occupied': return 'bg-healthcare-warning text-healthcare-warning';
      case 'Turnover': return 'bg-healthcare-primary text-healthcare-primary';
      case 'Available': return 'bg-healthcare-success text-healthcare-success';
      case 'Maintenance': return 'bg-healthcare-critical text-healthcare-critical';
      default: return 'bg-healthcare-border text-healthcare-text-secondary';
    }
  };

  return (
    <div className="space-y-6">
      {/* Occupancy Heatmap */}
      <div className="healthcare-card">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <div className="rounded-full bg-healthcare-primary/10 p-3">
              <Clock className="h-6 w-6 text-healthcare-primary" />
            </div>
            <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Daily Occupancy Pattern
            </h3>
          </div>
          <div className="flex gap-4">
            <div className="flex items-center gap-2">
              <div className="w-3 h-3 rounded-full bg-healthcare-critical/50" />
              <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Peak Hours
              </span>
            </div>
            <div className="flex items-center gap-2">
              <div className="w-3 h-3 rounded-full bg-healthcare-success/50" />
              <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Low Utilization
              </span>
            </div>
          </div>
        </div>
        <div className="h-64">
          <MetricChart
            height="64"
            yAxisLabel="Occupancy %"
            xAxisDataKey="hour"
          >
            <AreaChart data={heatmapData}>
              <defs>
                <linearGradient id="occupancyGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="var(--healthcare-critical)" stopOpacity={0.8}/>
                  <stop offset="95%" stopColor="var(--healthcare-success)" stopOpacity={0.2}/>
                </linearGradient>
              </defs>
              <Area
                type="monotone"
                dataKey="occupancy"
                stroke="var(--healthcare-primary)"
                fill="url(#occupancyGradient)"
              />
            </AreaChart>
          </MetricChart>
        </div>
      </div>

      {/* Room Status Grid */}
      <div className="grid grid-cols-4 gap-4">
        {Object.entries(roomStatus.summary).map(([status, count]) => (
          <div key={status} className="healthcare-card p-4">
            <div className="flex items-center justify-between mb-2">
              <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
                {status}
              </span>
              <span className={`text-lg font-bold ${getStatusColor(status)}`}>
                {count}
              </span>
            </div>
            <div className="h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
              <div 
                className={`h-full rounded-full ${getStatusColor(status).split(' ')[0]}`}
                style={{ width: `${(count / roomStatus.rooms.length) * 100}%` }}
              />
            </div>
          </div>
        ))}
      </div>

      {/* Room Details */}
      <div className="grid grid-cols-2 gap-6">
        <div className="healthcare-card">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-full bg-healthcare-warning/10 p-3">
              <DoorOpen className="h-6 w-6 text-healthcare-warning" />
            </div>
            <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Room Status
            </h3>
          </div>
          <div className="space-y-3 max-h-80 overflow-y-auto">
            {roomStatus.rooms.map(room => (
              <div key={room.id} className="healthcare-panel flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className={`w-2 h-2 rounded-full ${getStatusColor(room.status).split(' ')[0]}`} />
                  <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {room.id}
                  </span>
                </div>
                <div className="flex items-center gap-4">
                  <div className="text-right">
                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {room.status}
                    </div>
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {room.duration} min
                    </div>
                  </div>
                  <div className="flex items-center gap-1 text-healthcare-warning">
                    <Clock className="h-4 w-4" />
                    <span className="text-sm">{room.nextAction}m</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Turnover Metrics */}
        <div className="healthcare-card">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-full bg-healthcare-success/10 p-3">
              <RotateCcw className="h-6 w-6 text-healthcare-success" />
            </div>
            <h3 className="text-lg font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Turnover Performance
            </h3>
          </div>
          <div className="h-64">
            <MetricChart
              height="64"
              yAxisLabel="Minutes"
              xAxisDataKey="time"
            >
              <LineChart data={turnoverMetrics}>
                <Line 
                  type="monotone" 
                  dataKey="duration" 
                  stroke="var(--healthcare-success)"
                  strokeWidth={2}
                />
              </LineChart>
            </MetricChart>
          </div>
        </div>
      </div>

      {/* Quick Actions */}
      <div className="grid grid-cols-3 gap-4">
        {[
          { label: 'Expedite Turnover', icon: Zap },
          { label: 'View Maintenance Schedule', icon: RotateCcw },
          { label: 'Optimize Room Assignments', icon: DoorOpen }
        ].map((action, index) => (
          <button
            key={index}
            className="healthcare-card p-4 flex items-center gap-3 hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark cursor-pointer healthcare-transition"
          >
            <div className="rounded-full bg-healthcare-primary/10 p-2">
              <action.icon className="h-5 w-5 text-healthcare-primary" />
            </div>
            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {action.label}
            </span>
          </button>
        ))}
      </div>
    </div>
  );
};

export default SpaceUtilization;
