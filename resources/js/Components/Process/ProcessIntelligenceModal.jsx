import React from 'react';
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogFooter,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogAction,
  AlertDialogCancel
} from '@/Components/ui/alert-dialog';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import { TrendingUp, TrendingDown, AlertCircle } from 'lucide-react';

const ProcessIntelligenceModal = ({ 
  open, 
  onOpenChange, 
  type,
  data 
}) => {
  // Mock time-series data for trends
  const trendData = [
    { time: '08:00', value: 82 },
    { time: '10:00', value: 85 },
    { time: '12:00', value: 87 },
    { time: '14:00', value: 84 },
    { time: '16:00', value: 89 },
    { time: '18:00', value: 91 },
    { time: '20:00', value: 88 },
  ];

  const modalContent = {
    staffing: {
      title: "Staffing Intelligence Details",
      metrics: [
        { label: "Current Shift Coverage", value: "92%", trend: "+3%" },
        { label: "Staff-to-Patient Ratio", value: "1:4", status: "optimal" },
        { label: "Skill Mix Distribution", value: "Balanced", status: "good" },
        { label: "Upcoming Shift Risk", value: "Low", status: "good" }
      ],
      insights: [
        "Cross-training opportunities identified in ICU coverage",
        "Overtime trending down by 8% this month",
        "Staff satisfaction metrics improving"
      ]
    },
    beds: {
      title: "Bed Management Analytics",
      metrics: [
        { label: "Current Occupancy", value: "85%", trend: "+2%" },
        { label: "Average Turnover Time", value: "45min", trend: "-5min" },
        { label: "Discharge Accuracy", value: "94%", status: "good" },
        { label: "Bottleneck Risk", value: "Low", status: "good" }
      ],
      insights: [
        "Predicted discharge volumes above average for next 24h",
        "ED admission rate stabilizing",
        "Clean team performance exceeding targets"
      ]
    },
    transport: {
      title: "Transport System Analysis",
      metrics: [
        { label: "Active Requests", value: "12", status: "normal" },
        { label: "Average Wait Time", value: "8min", trend: "-2min" },
        { label: "Equipment Utilization", value: "88%", trend: "+4%" },
        { label: "On-Time Rate", value: "95%", status: "excellent" }
      ],
      insights: [
        "Route optimization reducing delays by 15%",
        "Equipment tracking system showing improved efficiency",
        "Peak demand periods well-staffed"
      ]
    },
    home: {
      title: "Hospital@Home Performance",
      metrics: [
        { label: "Active Patients", value: "45", trend: "+5" },
        { label: "Monitoring Compliance", value: "97%", status: "excellent" },
        { label: "Response Time", value: "5min", trend: "-2min" },
        { label: "Patient Satisfaction", value: "4.8/5", status: "excellent" }
      ],
      insights: [
        "Remote monitoring effectiveness at all-time high",
        "Virtual visit completion rate above target",
        "Medication adherence improving across cohort"
      ]
    },
    discharge: {
      title: "Care After Discharge Metrics",
      metrics: [
        { label: "Active Care Plans", value: "78", trend: "+12" },
        { label: "Follow-up Completion", value: "92%", trend: "+4%" },
        { label: "Readmission Risk", value: "Low", status: "good" },
        { label: "Care Plan Progress", value: "88%", status: "good" }
      ],
      insights: [
        "Medication reconciliation success rate improving",
        "Post-discharge support calls exceeding targets",
        "Patient engagement metrics trending positively"
      ]
    },
    chronic: {
      title: "Chronic Care Intelligence",
      metrics: [
        { label: "High Risk Conditions", value: "3", trend: "+1", status: "warning" },
        { label: "Avg Medication Adherence", value: "89%", trend: "+2%", status: "good" },
        { label: "Follow-up Compliance", value: "92%", trend: "+3%", status: "good" },
        { label: "Critical Alerts", value: "2", status: "warning" }
      ],
      insights: [
        "COPD and CKD showing elevated risk patterns",
        "Medication adherence improving across conditions",
        "Early intervention opportunities identified"
      ]
    }
  };

  const content = modalContent[type];
  if (!content) return null;

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent className="max-w-3xl">
        <AlertDialogHeader>
          <AlertDialogTitle>{content.title}</AlertDialogTitle>
          <AlertDialogDescription>
            <div className="space-y-6">
              {/* Trend Chart */}
              <div className="h-[200px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={trendData}>
                    <XAxis 
                      dataKey="time" 
                      stroke="currentColor" 
                      strokeOpacity={0.25}
                      fontSize="12px"
                    />
                    <YAxis 
                      stroke="currentColor"
                      fontSize="12px"
                    />
                    <Tooltip />
                    <Line 
                      type="monotone" 
                      dataKey="value" 
                      stroke="var(--healthcare-primary)"
                      strokeWidth={2}
                      dot={false}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>

              {/* Key Metrics */}
              <div className="grid grid-cols-2 gap-4">
                {content.metrics.map((metric, index) => (
                  <div 
                    key={index}
                    className="p-4 bg-healthcare-surface-secondary dark:bg-healthcare-surface-dark rounded-lg"
                  >
                    <div className="flex justify-between items-baseline mb-1">
                      <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {metric.label}
                      </span>
                      {metric.trend && (
                        <div className={`flex items-center text-sm ${
                          metric.trend.startsWith('+') || metric.trend.startsWith('-') && metric.trend.includes('min')
                            ? 'text-healthcare-success'
                            : 'text-healthcare-warning'
                        }`}>
                          {metric.trend.startsWith('+') ? (
                            <TrendingUp className="h-4 w-4 mr-1" />
                          ) : (
                            <TrendingDown className="h-4 w-4 mr-1" />
                          )}
                          {metric.trend}
                        </div>
                      )}
                    </div>
                    <div className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {metric.value}
                    </div>
                  </div>
                ))}
              </div>

              {/* Insights */}
              <div className="space-y-2">
                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Key Insights
                </h4>
                {content.insights.map((insight, index) => (
                  <div 
                    key={index}
                    className="flex items-start gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                  >
                    <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5 text-healthcare-primary dark:text-healthcare-primary-dark" />
                    <span>{insight}</span>
                  </div>
                ))}
              </div>
            </div>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Close</AlertDialogCancel>
          <AlertDialogAction>Export Report</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
};

export default ProcessIntelligenceModal;
