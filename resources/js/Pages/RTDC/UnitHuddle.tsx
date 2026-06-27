import { useState } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { BedNeedReadout } from '@/Components/RTDC/BedNeedReadout';
import { DischargeTierEntry } from '@/Components/RTDC/DischargeTierEntry';
import { DemandBySourceEntry } from '@/Components/RTDC/DemandBySourceEntry';
import { BarrierBoard } from '@/Components/RTDC/BarrierBoard';
import { ReliabilityTile } from '@/Components/RTDC/ReliabilityTile';
import { Section, Panel, KpiTile, metric } from '@/Components/system';
import {
  useUnits, usePrediction, useBarriers, useUpsertCapacity, useUpsertDemand,
  useDevelopPlan, useLiveCensus, useReliability,
} from '@/features/rtdc/hooks';
import { fetchBarriers } from '@/features/rtdc/api';
import axios from 'axios';
import { useQueryClient } from '@tanstack/react-query';

interface UnitHuddleProps { unitId?: number }

const TODAY = new Date().toISOString().slice(0, 10);

export default function UnitHuddle({ unitId = 1 }: UnitHuddleProps) {
  const horizon = 'by_2pm';
  useLiveCensus();

  const qc = useQueryClient();
  const { data: units } = useUnits();
  const unit = units?.find((u) => u.unit_id === unitId);
  const { data: prediction } = usePrediction(unitId, TODAY, horizon);
  const { data: barriers } = useBarriers(unitId);
  const { data: reliability } = useReliability(unitId);

  const capacityMut = useUpsertCapacity(unitId);
  const demandMut = useUpsertDemand(unitId);
  const planMut = useDevelopPlan(unitId);

  const [tiers, setTiers] = useState({ definite: 0, probable: 0, possible: 0 });
  const [demand, setDemand] = useState({ ed: 0, or: 0, transfer: 0, direct: 0 });

  const saveCapacity = () => capacityMut.mutate({ service_date: TODAY, horizon, ...tiers });
  const saveDemand = () => demandMut.mutate({ service_date: TODAY, horizon, ...demand });
  const computePlan = () => planMut.mutate({ serviceDate: TODAY, horizon });

  const resolveBarrier = async (id: number) => {
    await axios.post(`/api/rtdc/barriers/${id}/resolve`);
    qc.setQueryData(['rtdc', 'barriers', unitId], await fetchBarriers(unitId));
  };

  const occ = unit ? unit.census.occupied / Math.max(1, unit.staffed_bed_count) : 0;
  const censusMetric = unit
    ? metric({
        key: 'live-census',
        label: 'Live Census',
        value: unit.census.occupied,
        display: `${unit.census.occupied}/${unit.staffed_bed_count}`,
        status: occ >= 0.95 ? 'critical' : occ >= 0.85 ? 'warning' : 'success',
        caption: `${unit.census.available} available · safe additional ${unit.census.acuity_adjusted_capacity}`,
        definition: 'Occupied beds over staffed beds for this unit, from the live census feed.',
      })
    : null;

  const linkBtn = 'text-sm font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:underline';

  return (
    <RTDCPageLayout title={unit ? `Unit Huddle — ${unit.name}` : 'Unit Huddle'} subtitle="Real-Time Demand Capacity — Step 1–3">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2 flex flex-col gap-5">
          <Section title="Step 1 — Predict discharges" icon="heroicons:arrow-up-tray"
                   actions={<button onClick={saveCapacity} className={linkBtn}>Save capacity</button>}>
            <Panel className="p-4"><DischargeTierEntry {...tiers} onChange={setTiers} /></Panel>
          </Section>
          <Section title="Step 2 — Predict demand" icon="heroicons:arrow-down-tray"
                   actions={<button onClick={saveDemand} className={linkBtn}>Save demand</button>}>
            <Panel className="p-4"><DemandBySourceEntry {...demand} onChange={setDemand} /></Panel>
          </Section>
          <Section title="Barriers" icon="heroicons:exclamation-triangle">
            <Panel className="p-4"><BarrierBoard barriers={barriers ?? []} onResolve={resolveBarrier} /></Panel>
          </Section>
        </div>

        <aside className="flex flex-col gap-5">
          {censusMetric && <KpiTile metric={censusMetric} detailed />}
          <button onClick={computePlan} className="rounded-md bg-healthcare-primary dark:bg-healthcare-primary-dark p-3 text-white font-medium">
            Step 3 — Compute bed-need
          </button>
          {prediction && (
            <BedNeedReadout bedNeed={prediction.bed_need} capacityNow={prediction.capacity_now} demandExpected={prediction.demand_expected} />
          )}
          <ReliabilityTile score={reliability?.reliability_score ?? null} />
        </aside>
      </div>
    </RTDCPageLayout>
  );
}
