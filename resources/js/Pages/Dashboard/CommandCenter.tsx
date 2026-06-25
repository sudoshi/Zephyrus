// resources/js/Pages/Dashboard/CommandCenter.tsx
import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { parseCommandCenterData } from '@/types/commandCenter';
import { CommandCenterView } from '@/Components/CommandCenter/CommandCenterView';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';

const REFRESH_MS = 45_000;

export default function CommandCenter({ data }: { data: unknown }) {
  const cc = useMemo(() => parseCommandCenterData(data), [data]);
  const [refreshedLabel, setRefreshedLabel] = useState('just now');

  // Periodic background refresh of the payload only.
  useEffect(() => {
    const id = setInterval(() => {
      router.reload({ only: ['data'] });
    }, REFRESH_MS);
    return () => clearInterval(id);
  }, []);

  // Reset the freshness label whenever new data arrives.
  useEffect(() => {
    setRefreshedLabel('just now');
    const id = setInterval(() => setRefreshedLabel('moments ago'), 15_000);
    return () => clearInterval(id);
  }, [cc.generatedAtIso]);

  const handleRefresh = () => router.reload({ only: ['data'] });

  return (
    <DashboardLayout>
      <Head title="Operations Command Center - ZephyrusOR" />
      <PageContentLayout
        title="Hospital Operations Command Center"
        subtitle="House-wide demand, capacity, flow & forecast"
        headerContent={<RoleSwitcher />}
      >
        <CommandCenterView data={cc} onRefresh={handleRefresh} refreshedLabel={refreshedLabel} />
      </PageContentLayout>
    </DashboardLayout>
  );
}
