import { AlertTriangle, CheckCircle2, CircleDashed, FlaskConical, Gauge, PackageX } from 'lucide-react';
import type { PharmacyQueueForecast, PharmacyStockoutForecast, PharmacyStockoutForecastRow } from '@/features/pharmacy/forecast-schemas';

const AVAILABILITY_META = {
  observed: { label: 'Observed stockout', Icon: PackageX, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  available: { label: 'Forecast available', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  low_confidence: { label: 'Low confidence', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  velocity_only: { label: 'Velocity only', Icon: Gauge, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
  unavailable: { label: 'Unavailable', Icon: CircleDashed, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
} as const;

const BAND_LABEL = { low: 'Low pressure', watch: 'Watch', elevated: 'Elevated pressure' } as const;

function ModelNote({ forecast }: { forecast: PharmacyQueueForecast | PharmacyStockoutForecast }) {
  if (forecast.provenance === null) return null;
  const window = forecast.target === 'verification_queue_depth'
    ? forecast.provenance.queueTrainingWindow
    : forecast.provenance.stockoutTrainingWindow;

  return <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
    {forecast.provenance.syntheticLabel} Model {forecast.provenance.modelVersion}; calibrated {new Date(forecast.provenance.calibratedAt).toLocaleDateString()}; evaluated {new Date(window.evaluateFrom).toLocaleDateString()}–{new Date(window.evaluateTo).toLocaleDateString()}.
  </p>;
}

export function QueueForecastPanel({ forecast }: { forecast: PharmacyQueueForecast }) {
  const evaluation = forecast.provenance?.queueEvaluation;

  return <section aria-labelledby="queue-forecast-title" className="rounded-lg border border-dashed border-healthcare-info/60 bg-healthcare-info/5 p-4">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 id="queue-forecast-title" className="inline-flex items-center gap-2 font-semibold"><FlaskConical className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Synthetic planning forecast · verification queue</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Separate from the observed queue and all governed SLA states. Horizon: {forecast.horizonHours} hours.</p>
      </div>
      <span className={`inline-flex items-center gap-1 text-sm font-medium ${forecast.status === 'available' ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-warning dark:text-healthcare-warning-dark'}`}>
        {forecast.status === 'available' ? <CheckCircle2 className="size-4" aria-hidden="true" /> : <AlertTriangle className="size-4" aria-hidden="true" />}
        {forecast.status.replaceAll('_', ' ')}
      </span>
    </div>
    <p className="mt-2 text-sm">{forecast.explanation}</p>
    {forecast.points.length > 0 ? <div className="mt-3 overflow-x-auto">
      <table className="w-full min-w-[42rem] text-sm">
        <caption className="sr-only">Synthetic hourly verification queue-depth projection</caption>
        <thead className="text-left text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th scope="col" className="py-1 pr-3 font-medium">Hour</th><th scope="col" className="py-1 pr-3 font-medium">Projected depth</th><th scope="col" className="py-1 pr-3 font-medium">Uncertainty interval</th><th scope="col" className="py-1 pr-3 font-medium">Scheduled demand</th><th scope="col" className="py-1 font-medium">Historical arrivals / completions</th></tr></thead>
        <tbody>{forecast.points.map((point) => <tr key={point.horizonHour} className="border-t border-healthcare-border dark:border-healthcare-border-dark">
          <th scope="row" className="py-1.5 pr-3 text-left font-normal tabular-nums">+{point.horizonHour} h · {new Date(point.at).toLocaleTimeString([], { hour: 'numeric' })}</th>
          <td className="py-1.5 pr-3 font-semibold tabular-nums">{point.forecastDepth}</td>
          <td className="py-1.5 pr-3 tabular-nums">{point.lowerDepth}–{point.upperDepth}</td>
          <td className="py-1.5 pr-3 tabular-nums">{point.scheduledDemand} ({point.scheduledDemandContribution >= 0 ? '+' : ''}{point.scheduledDemandContribution})</td>
          <td className="py-1.5 tabular-nums">{point.historicalArrivalRate} / {point.historicalCompletionRate}</td>
        </tr>)}</tbody>
      </table>
    </div> : <p className="mt-3 rounded-md border border-healthcare-border p-2 text-sm dark:border-healthcare-border-dark">No queue projection is available. The observed queue remains authoritative.</p>}
    {evaluation ? <p className="mt-2 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Backtest: MAE {evaluation.mae}; RMSE {evaluation.rmse}. Both are lower than the hour-of-week and last-value baselines: {evaluation.beatsBaselines ? 'yes' : 'no'}.</p> : null}
    <ModelNote forecast={forecast} />
  </section>;
}

function StockoutRow({ row }: { row: PharmacyStockoutForecastRow }) {
  const meta = AVAILABILITY_META[row.availability];

  return <tr className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark">
    <th scope="row" className="py-2 pr-3 text-left font-normal"><span className="font-medium">{row.medicationLabel}</span><span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.stationLabel} · {row.unitLabel ?? 'Unit unavailable'} · {row.terminologyStatus.replaceAll('_', ' ')}</span></th>
    <td className="py-2 pr-3"><span className={`inline-flex items-center gap-1 font-medium ${meta.className}`}><meta.Icon className="size-4" aria-hidden="true" />{meta.label}</span><span className="block text-xs">{row.band ? BAND_LABEL[row.band] : 'No probability band'}</span></td>
    <td className="py-2 pr-3 font-semibold tabular-nums">{row.probability === null ? '—' : `${Math.round(row.probability * 100)}%`}</td>
    <td className="py-2 pr-3 tabular-nums">{row.inventory.onHand === null || row.inventory.parLevel === null ? 'not available' : `${row.inventory.onHand} / ${row.inventory.parLevel}`}<span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.inventory.capturedAt ? `as of ${new Date(row.inventory.capturedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : 'cutoff unavailable'}</span></td>
    <td className="py-2 text-xs"><span className="tabular-nums">Vend {row.velocityPressure.vendUnitsPerHour}/h · refill {row.velocityPressure.refillUnitsPerHour}/h</span><span className="mt-1 block text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.explanation}</span></td>
  </tr>;
}

export function StockoutForecastPanel({ forecast }: { forecast: PharmacyStockoutForecast }) {
  const evaluation = forecast.provenance?.stockoutEvaluation;

  return <section aria-labelledby="stockout-forecast-title" className="rounded-lg border border-dashed border-healthcare-info/60 bg-healthcare-info/5 p-4">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 id="stockout-forecast-title" className="inline-flex items-center gap-2 font-semibold"><FlaskConical className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Synthetic planning forecast · stockout pressure</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Station-medication aggregates only. Existing stockouts remain observed facts; missing inventory remains velocity-only. Horizon: {forecast.horizonHours} hours.</p>
      </div>
      <span className="text-sm font-medium tabular-nums">{forecast.coverage.probabilityAvailable}/{forecast.coverage.stationMedicationPairs} probability coverage</span>
    </div>
    <p className="mt-2 text-sm">{forecast.explanation}</p>
    {forecast.rows.length > 0 ? <div className="mt-3 overflow-x-auto">
      <table className="w-full min-w-[50rem] text-sm">
        <caption className="sr-only">Synthetic station-medication stockout-pressure forecast</caption>
        <thead className="text-left text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th scope="col" className="py-1 pr-3 font-medium">Medication / station</th><th scope="col" className="py-1 pr-3 font-medium">Availability</th><th scope="col" className="py-1 pr-3 font-medium">Probability</th><th scope="col" className="py-1 pr-3 font-medium">On hand / par</th><th scope="col" className="py-1 font-medium">Velocity context</th></tr></thead>
        <tbody>{forecast.rows.map((row) => <StockoutRow key={`${row.stationId}-${row.localCode}`} row={row} />)}</tbody>
      </table>
    </div> : <p className="mt-3 rounded-md border border-healthcare-border p-2 text-sm dark:border-healthcare-border-dark">No station-medication forecast rows are available.</p>}
    {evaluation ? <p className="mt-2 text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Backtest: AUC {evaluation.discriminationAuc}; Brier {evaluation.brierScore} versus base-rate {evaluation.naiveBaseline.brierScore}; baseline beaten: {evaluation.beatsBaseline ? 'yes' : 'no'}.</p> : null}
    <ModelNote forecast={forecast} />
  </section>;
}
