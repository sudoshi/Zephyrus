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

const VitalsReviewContent = ({ data }) => (
  <>
    <AlertDialogHeader>
      <AlertDialogTitle>Review Vital Signs</AlertDialogTitle>
      <AlertDialogDescription>
        <div className="space-y-2">
          <div className="grid grid-cols-2 gap-4 mt-2">
            <div>
              <span className="text-sm font-medium">Blood Pressure</span>
              <p className="text-lg font-semibold text-healthcare-critical">{data.bp}</p>
            </div>
            <div>
              <span className="text-sm font-medium">Heart Rate</span>
              <p className="text-lg font-semibold text-healthcare-critical">{data.hr} bpm</p>
            </div>
            <div>
              <span className="text-sm font-medium">SpO2</span>
              <p className="text-lg font-semibold text-healthcare-warning">{data.spo2}%</p>
            </div>
            <div>
              <span className="text-sm font-medium">Temperature</span>
              <p className="text-lg font-semibold">{data.temp}°F</p>
            </div>
          </div>
          <div className="mt-4">
            <span className="text-sm font-medium">Trend</span>
            <p className="text-sm text-gray-600 dark:text-gray-400">
              BP trending upward over last 2 hours. HR elevated but stable.
            </p>
          </div>
        </div>
      </AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel>Close</AlertDialogCancel>
      <AlertDialogAction>Contact Provider</AlertDialogAction>
      <AlertDialogAction>Update Care Plan</AlertDialogAction>
    </AlertDialogFooter>
  </>
);

const DeviceCheckContent = ({ data }) => (
  <>
    <AlertDialogHeader>
      <AlertDialogTitle>Device Connection Issue</AlertDialogTitle>
      <AlertDialogDescription>
        <div className="space-y-2">
          <div className="mt-2">
            <span className="text-sm font-medium">Device ID</span>
            <p className="text-base">{data.deviceId}</p>
          </div>
          <div>
            <span className="text-sm font-medium">Last Connected</span>
            <p className="text-base">{data.lastConnected}</p>
          </div>
          <div>
            <span className="text-sm font-medium">Troubleshooting Steps</span>
            <ul className="mt-1 text-sm list-disc list-inside">
              <li>Check device power status</li>
              <li>Verify network connection</li>
              <li>Restart device if necessary</li>
            </ul>
          </div>
        </div>
      </AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel>Close</AlertDialogCancel>
      <AlertDialogAction>Run Diagnostics</AlertDialogAction>
      <AlertDialogAction>Contact Support</AlertDialogAction>
    </AlertDialogFooter>
  </>
);

const MedicationContent = ({ data }) => (
  <>
    <AlertDialogHeader>
      <AlertDialogTitle>Missed Medication</AlertDialogTitle>
      <AlertDialogDescription>
        <div className="space-y-2">
          <div className="mt-2">
            <span className="text-sm font-medium">Medication</span>
            <p className="text-base">{data.medication}</p>
          </div>
          <div>
            <span className="text-sm font-medium">Scheduled Time</span>
            <p className="text-base">{data.scheduledTime}</p>
          </div>
          <div>
            <span className="text-sm font-medium">Last Dose</span>
            <p className="text-base">{data.lastDose}</p>
          </div>
          <div>
            <span className="text-sm font-medium">Contact Information</span>
            <p className="text-base">{data.contact}</p>
          </div>
        </div>
      </AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel>Close</AlertDialogCancel>
      <AlertDialogAction>Contact Patient</AlertDialogAction>
      <AlertDialogAction>Update Schedule</AlertDialogAction>
    </AlertDialogFooter>
  </>
);

const AlertModal = ({ open, onOpenChange, type, data }) => {
  const contentMap = {
    vitals: VitalsReviewContent,
    device: DeviceCheckContent,
    medication: MedicationContent,
  };

  const Content = contentMap[type];

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        {Content && <Content data={data} />}
      </AlertDialogContent>
    </AlertDialog>
  );
};

export default AlertModal;
