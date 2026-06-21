import { useState } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { BedNeedReadout } from '@/Components/RTDC/BedNeedReadout';
import { DischargeTierEntry } from '@/Components/RTDC/DischargeTierEntry';
import { DemandBySourceEntry } from '@/Components/RTDC/DemandBySourceEntry';
import { BarrierBoard } from '@/Components/RTDC/BarrierBoard';
import { ReliabilityTile } from '@/Components/RTDC/ReliabilityTile';
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

  return (
    <RTDCPageLayout title={unit ? `Unit Huddle — ${unit.name}` : 'Unit Huddle'} subtitle="Real-Time Demand Capacity — Step 1–3">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-[var(--space-6)]">
        <section className="lg:col-span-2 flex flex-col gap-[var(--space-5)]">
          <div>
            <h3 className="text-panel-title">Step 1 — Predict discharges</h3>
            <DischargeTierEntry {...tiers} onChange={setTiers} />
            <button onClick={saveCapacity} className="mt-[var(--space-2)] text-caption underline">Save capacity</button>
          </div>
          <div>
            <h3 className="text-panel-title">Step 2 — Predict demand</h3>
            <DemandBySourceEntry {...demand} onChange={setDemand} />
            <button onClick={saveDemand} className="mt-[var(--space-2)] text-caption underline">Save demand</button>
          </div>
          <div>
            <h3 className="text-panel-title">Barriers</h3>
            <BarrierBoard barriers={barriers ?? []} onResolve={resolveBarrier} />
          </div>
        </section>

        <aside className="flex flex-col gap-[var(--space-5)]">
          {unit && (
            <div className="rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-[var(--space-5)]">
              <div className="text-label">Live Census</div>
              <div className="text-value">{unit.census.occupied}/{unit.staffed_bed_count}</div>
              <div className="text-caption">
                {unit.census.available} available · safe additional capacity {unit.census.acuity_adjusted_capacity}
              </div>
            </div>
          )}
          <button onClick={computePlan} className="rounded-[var(--radius-md)] bg-[var(--primary)] p-[var(--space-3)] text-white">
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
