import React, { useEffect, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PatientFlowNavigatorView from '@/Components/PatientFlowNavigator/PatientFlowNavigator';
import { commandRoleForLens, navigatorUrlForRole } from '@/features/patientFlowNavigator/personaBridge';
import { useCommandCenterStore } from '@/stores/commandCenterStore';
import type { FlowLens, FlowUnitSummary } from '@/features/patientFlowNavigator/types';

interface PatientFlowNavigatorPageProps {
  flowLens?: FlowLens | null;
  flowUnits?: FlowUnitSummary[];
}

// P4b: the hand-rolled TopNavbar shell converged onto the unified DashboardLayout
// (fullBleed — the 4D navigator needs the uncapped width). The Flow Window lens +
// unit summaries (flow-window Phase 4) pass through to the navigator view.
export default function PatientFlowNavigator({ flowLens = null, flowUnits = [] }: PatientFlowNavigatorPageProps) {
  const role = useCommandCenterStore((s) => s.role);
  const setRole = useCommandCenterStore((s) => s.setRole);
  const lensRole = flowLens?.role_id ?? null;
  const mountedRef = useRef(false);

  // F-1 ruling: one canonical persona state. On mount/lens change the SERVER
  // lens is the truth the switcher tabs must reflect …
  useEffect(() => {
    const derived = commandRoleForLens(lensRole);
    setRole(derived);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lensRole]);

  // … and a subsequent switch on THIS page is a full server persona
  // transition through EnforceFlowLens — never a client-only relabel.
  useEffect(() => {
    if (!mountedRef.current) {
      mountedRef.current = true;
      return;
    }
    if (role === commandRoleForLens(lensRole)) return;
    router.visit(navigatorUrlForRole(window.location.href, role), { preserveScroll: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [role]);

  return (
    <DashboardLayout fullBleed>
      <Head title="Patient Flow 4D Navigator - RTDC" />
      <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        <PatientFlowNavigatorView lens={flowLens} units={flowUnits} />
      </div>
    </DashboardLayout>
  );
}
