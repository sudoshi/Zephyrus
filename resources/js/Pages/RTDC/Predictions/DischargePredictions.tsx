import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Dashboard/Card';
import { ArrowRightCircle } from 'lucide-react';

/**
 * RTDC › Predictions › Discharge Predictions
 *
 * Defensive stub. `RTDCDashboardController@dischargePredictions` renders this
 * component but is not currently wired to a route — the live discharge surface
 * is `/rtdc/predictions/discharge` → `RTDC/DischargePriorities`. This page
 * exists so that controller method can never 500 if it is ever routed, and
 * keeps every Inertia::render target backed by a real component.
 */
export default function DischargePredictions() {
  return (
    <AuthenticatedLayout
      header={
        <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark leading-tight">
          Discharge Predictions
        </h2>
      }
    >
      <Head title="Discharge Predictions" />
      <div className="p-4">
        <Card className="p-4">
          <div className="flex items-start gap-3">
            <ArrowRightCircle className="h-5 w-5 mt-0.5 text-healthcare-primary dark:text-healthcare-primary-dark" />
            <div>
              <h1 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Predicted discharges
              </h1>
              <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Forecasts expected discharges by unit and time horizon to inform the bed-capacity
                plan. The live discharge-priority workspace is at{' '}
                <span className="font-medium">Predictions › Discharge</span>.
              </p>
            </div>
          </div>
        </Card>
      </div>
    </AuthenticatedLayout>
  );
}
