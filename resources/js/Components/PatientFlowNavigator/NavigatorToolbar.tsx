import React from 'react';
import { Bot, Home, Pause, Play, Radio, ScanSearch } from 'lucide-react';
import type {
  OccupancySummary,
  PatientFlowAmbient,
  PatientFlowFilters,
  PatientFlowSummary,
  PatientLayerState,
} from '@/features/patientFlowNavigator/types';

export interface NavigatorMetrics {
  active: number;
  events: number;
  occupiedLocations: number;
}

export interface LayerControl {
  key: keyof PatientLayerState;
  label: string;
  id: string;
}

interface NavigatorToolbarProps {
  summary: PatientFlowSummary | null;
  ambient: PatientFlowAmbient | null;
  lensTitle: string | null;
  chronobar: React.ReactNode;
  playing: boolean;
  live: boolean;
  speed: number;
  filters: PatientFlowFilters;
  floors: string[];
  services: string[];
  categories: string[];
  layers: PatientLayerState;
  layerControls: LayerControl[];
  barrierFinder: boolean;
  metrics: NavigatorMetrics;
  occupancy: OccupancySummary;
  eddyEnabled: boolean;
  onTogglePlay: () => void;
  onToggleLive: () => void;
  onResetCamera: () => void;
  onFocusPatients: () => void;
  onAskEddy: () => void;
  onSpeedChange: (speed: number) => void;
  onFiltersChange: (patch: Partial<PatientFlowFilters>) => void;
  onLayerChange: (key: keyof PatientLayerState, value: boolean) => void;
  onBarrierFinderChange: (value: boolean) => void;
}

export default function NavigatorToolbar({
  summary,
  ambient,
  lensTitle,
  chronobar,
  playing,
  live,
  speed,
  filters,
  floors,
  services,
  categories,
  layers,
  layerControls,
  barrierFinder,
  metrics,
  occupancy,
  eddyEnabled,
  onTogglePlay,
  onToggleLive,
  onResetCamera,
  onFocusPatients,
  onAskEddy,
  onSpeedChange,
  onFiltersChange,
  onLayerChange,
  onBarrierFinderChange,
}: NavigatorToolbarProps) {
  return (
    <aside className="patient-flow-toolbar" aria-label="Navigator controls">
      <div className="patient-flow-brand">
        <strong>Patient Flow 4D</strong>
        <span>
          {summary ? `${summary.patients} pts / ${summary.normalized_events} events` : 'Loading'}
          {lensTitle ? ` · ${lensTitle}` : ''}
        </span>
      </div>

      {summary?.source && (
        <div className={`patient-flow-source-status ${summary.source.freshness}`} role="status">
          <strong>{summary.source.freshness === 'stale' ? 'Historical data' : `${summary.source.freshness} data`}</strong>
          <span>{summary.source.mode} · {summary.source.system}</span>
          <small>
            {summary.data_extent.first_event_at && summary.data_extent.last_event_at
              ? `${new Date(summary.data_extent.first_event_at).toLocaleString()} to ${new Date(summary.data_extent.last_event_at).toLocaleString()}`
              : 'No event extent available'}
          </small>
        </div>
      )}

      {chronobar}

      <div className="patient-flow-buttons">
        <button
          className={`patient-flow-icon-button ${playing ? 'active' : ''}`}
          type="button"
          title={playing ? 'Pause replay' : 'Play replay in the displayed window'}
          onClick={onTogglePlay}
        >
          {playing ? <Pause /> : <Play />}
        </button>
        <button
          className={`patient-flow-icon-button ${live ? 'active' : ''}`}
          type="button"
          title="Stream latest stored events"
          onClick={onToggleLive}
        >
          <Radio />
        </button>
        <button className="patient-flow-icon-button" type="button" title="Reset view" onClick={onResetCamera}>
          <Home />
        </button>
        <button
          className="patient-flow-icon-button"
          type="button"
          title="Focus active patients"
          onClick={onFocusPatients}
        >
          <ScanSearch />
        </button>
        {eddyEnabled && (
          <button
            className="patient-flow-icon-button"
            type="button"
            title="Ask Eddy about timer and service-line pressure"
            onClick={onAskEddy}
          >
            <Bot />
          </button>
        )}
      </div>

      <div className="patient-flow-control-grid">
        <label htmlFor="flow-floor">Floor</label>
        <select
          id="flow-floor"
          value={filters.floor}
          onChange={(event) => onFiltersChange({ floor: event.target.value })}
        >
          <option value="all">All</option>
          {floors.map((floor) => (
            <option key={floor} value={floor}>Floor {floor}</option>
          ))}
        </select>

        <label htmlFor="flow-service">Service</label>
        <select
          id="flow-service"
          value={filters.serviceLine}
          onChange={(event) => onFiltersChange({ serviceLine: event.target.value })}
        >
          <option value="all">All</option>
          {services.map((service) => (
            <option key={service} value={service}>{service.replaceAll('_', ' ')}</option>
          ))}
        </select>

        <label htmlFor="flow-category">Event</label>
        <select
          id="flow-category"
          value={filters.category}
          onChange={(event) => onFiltersChange({ category: event.target.value })}
        >
          <option value="all">All</option>
          {categories.map((category) => (
            <option key={category} value={category}>{category.replaceAll('_', ' ')}</option>
          ))}
        </select>

        <label htmlFor="flow-speed">Speed</label>
        <select id="flow-speed" value={speed} onChange={(event) => onSpeedChange(Number(event.target.value))}>
          <option value={15}>15m/s</option>
          <option value={60}>1h/s</option>
          <option value={240}>4h/s</option>
          <option value={720}>12h/s</option>
        </select>

        <label htmlFor="flow-search">Find</label>
        <input
          id="flow-search"
          type="search"
          placeholder="PT, bed, service"
          value={filters.search}
          onChange={(event) => onFiltersChange({ search: event.target.value })}
        />
      </div>

      <fieldset className="patient-flow-layer-grid">
        <legend>Layers</legend>
        {layerControls.map(({ key, label, id }) => (
          <div className="patient-flow-checkbox-row" key={key}>
            <input
              id={id}
              type="checkbox"
              role="switch"
              checked={layers[key]}
              onChange={(event) => onLayerChange(key, event.target.checked)}
            />
            <label htmlFor={id}>{label}</label>
          </div>
        ))}
        <div className="patient-flow-checkbox-row">
          <input
            id="flow-barrier-finder"
            type="checkbox"
            role="switch"
            checked={barrierFinder}
            onChange={(event) => onBarrierFinderChange(event.target.checked)}
          />
          <label htmlFor="flow-barrier-finder" title="Find all barriers and delays">Barriers</label>
        </div>
      </fieldset>

      <div className="patient-flow-metrics">
        <div><span>{metrics.active}</span><small>Active</small></div>
        <div><span>{metrics.events}</span><small>Events</small></div>
        <div><span>{metrics.occupiedLocations}</span><small>Locations</small></div>
        <div><span>{ambient?.summary.eventCount ?? summary?.ambient_signals ?? 0}</span><small>Ambient</small></div>
      </div>

      <section className="patient-flow-occupancy-rollup" aria-label="Occupancy timer rollup">
        <div className="patient-flow-rollup-grid">
          <div><span>{occupancy.delayed}</span><small>Delayed</small></div>
          <div><span>{occupancy.readyToMove}</span><small>Ready</small></div>
          <div><span>{occupancy.transportDelays}</span><small>Transport</small></div>
          <div><span>{occupancy.evsDelays}</span><small>EVS</small></div>
        </div>

        {(occupancy.durationRisks ?? 0) > 0 && (
          <div className="patient-flow-duration-risk">
            <strong>Duration risk</strong>
            <span>{occupancy.durationRisks} elapsed-time signals</span>
            <small>Elapsed occupancy signal; not a verified operational barrier.</small>
          </div>
        )}

        {occupancy.serviceLines.length > 0 && (
          <ol className="patient-flow-service-rollup">
            {occupancy.serviceLines.slice(0, 3).map((item) => (
              <li key={item.serviceLine}>
                <span>{item.serviceLine}</span>
                <strong>{item.occupied}</strong>
                <small>{item.delayed} delayed / {item.watch} watch</small>
              </li>
            ))}
          </ol>
        )}

        {Boolean(occupancy.topBarriers?.length) && (
          <ol className="patient-flow-barrier-rollup">
            {occupancy.topBarriers?.slice(0, 3).map((item) => (
              <li key={item.barrierCode ?? `${item.label}-${item.reason ?? 'none'}`}>
                <span>{item.label}</span>
                <strong>{item.count}</strong>
                <small>
                  {item.reason ?? item.eddySummary ?? 'Barrier active'}
                  {item.ownerRole ? ` · Owner: ${item.ownerRole.replaceAll('_', ' ')}` : ''}
                </small>
              </li>
            ))}
          </ol>
        )}
      </section>
    </aside>
  );
}
