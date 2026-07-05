import React from 'react';
import { Home, Pause, Play, Radio, ScanSearch } from 'lucide-react';
import type {
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
  metrics: NavigatorMetrics;
  onTogglePlay: () => void;
  onToggleLive: () => void;
  onResetCamera: () => void;
  onFocusPatients: () => void;
  onSpeedChange: (speed: number) => void;
  onFiltersChange: (patch: Partial<PatientFlowFilters>) => void;
  onLayerChange: (key: keyof PatientLayerState, value: boolean) => void;
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
  metrics,
  onTogglePlay,
  onToggleLive,
  onResetCamera,
  onFocusPatients,
  onSpeedChange,
  onFiltersChange,
  onLayerChange,
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

      {chronobar}

      <div className="patient-flow-buttons">
        <button
          className={`patient-flow-icon-button ${playing ? 'active' : ''}`}
          type="button"
          title={playing ? 'Pause replay' : 'Play replay (loops the past 24h)'}
          onClick={onTogglePlay}
        >
          {playing ? <Pause /> : <Play />}
        </button>
        <button
          className={`patient-flow-icon-button ${live ? 'active' : ''}`}
          type="button"
          title="Live stream"
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
      </fieldset>

      <div className="patient-flow-metrics">
        <div><span>{metrics.active}</span><small>Active</small></div>
        <div><span>{metrics.events}</span><small>Events</small></div>
        <div><span>{metrics.occupiedLocations}</span><small>Locations</small></div>
        <div><span>{ambient?.summary.eventCount ?? summary?.ambient_signals ?? 0}</span><small>Ambient</small></div>
      </div>
    </aside>
  );
}
