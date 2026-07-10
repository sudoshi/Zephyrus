import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

function ZephyrusMark({ className = '' }: { className?: string }) {
  return (
    <img src="/images/zephyrus-icon.png" alt="" aria-hidden="true" className={`${className} object-contain`} />
  );
}

const FEATURES: { icon: string; label: string; desc: string }[] = [
  { icon: 'lucide:layout-dashboard', label: 'Operations Command Center', desc: 'House-wide situational awareness across the care continuum' },
  { icon: 'lucide:activity', label: 'Real-Time Demand & Capacity', desc: 'Live census, boarding, and bed-demand signals' },
  { icon: 'lucide:scissors', label: 'Perioperative & OR', desc: 'Block utilization, FCOTS, and case flow' },
  { icon: 'lucide:route', label: 'Patient Flow & Throughput', desc: 'ED, admissions, discharges, and boarding' },
  { icon: 'lucide:trending-up', label: 'Forecasting & Surge', desc: 'Capacity forecasts and early surge signals' },
];

const PILLS: { label: string; items: string[]; tone: string }[] = [
  { label: 'Modules', tone: 'text-indigo-300 bg-indigo-500/10 border-indigo-400/20',
    items: ['Command Center', 'RTDC', 'Perioperative', 'Patient Flow', 'Care Progression'] },
  { label: 'Capabilities', tone: 'text-cyan-300 bg-cyan-500/10 border-cyan-400/20',
    items: ['Live Census', 'Bed Management', 'Surge Forecasting', 'Block Utilization'] },
  { label: 'Standards & Security', tone: 'text-sky-300 bg-sky-500/10 border-sky-400/20',
    items: ['HIPAA', 'RBAC', 'OIDC SSO', 'Audit Logging', 'PHI Isolation'] },
];

export function AuthHero() {
  return (
    <motion.div
      initial={{ opacity: 0, x: -20 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
      className="w-full max-w-[640px] rounded-3xl border border-white/[0.08] bg-[#08090f]/55 backdrop-blur-2xl p-7 sm:p-9"
    >
      {/* Header */}
      <div className="flex items-center gap-3">
        <ZephyrusMark className="h-11 w-11" />
        <div>
          <h1 className="text-3xl font-extralight tracking-[0.18em] uppercase text-slate-100 leading-none">
            Zephyrus
          </h1>
        </div>
      </div>
      <p className="mt-3 text-xs font-medium uppercase tracking-[0.22em] text-slate-400">
        Healthcare Operations Platform
      </p>
      <div className="mt-4 h-0.5 w-12 rounded bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-400" />

      <p className="mt-4 text-sm leading-relaxed text-slate-400">
        Real-time hospital demand &amp; capacity, perioperative flow, and house-wide
        situational awareness — one operations command center for the whole hospital.
      </p>

      {/* Features — hidden on small screens */}
      <div className="mt-6 hidden lg:flex flex-col gap-2.5">
        {FEATURES.map((f) => (
          <div key={f.label} className="flex items-start gap-2.5">
            <Icon icon={f.icon} className="mt-0.5 w-4 h-4 shrink-0 text-cyan-400/80" />
            <div>
              <span className="block text-sm font-semibold text-slate-200 leading-tight">{f.label}</span>
              <span className="block text-xs text-slate-500 leading-snug">{f.desc}</span>
            </div>
          </div>
        ))}
      </div>

      {/* Pills — hidden on small screens */}
      <div className="mt-6 hidden lg:block space-y-3">
        {PILLS.map((group) => (
          <div key={group.label}>
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
              {group.label}
            </p>
            <div className="flex flex-wrap gap-1.5">
              {group.items.map((p) => (
                <span key={p} className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${group.tone}`}>
                  {p}
                </span>
              ))}
            </div>
          </div>
        ))}
      </div>

      <div className="mt-7 hidden lg:block border-t border-white/[0.06] pt-3">
        <span className="text-xs tracking-wide text-slate-500">
          Acumenus Data Sciences
        </span>
      </div>
    </motion.div>
  );
}
