import React from 'react';
import { Bookmark, Bot, Home, Pause, Play, Radio, ScanSearch } from 'lucide-react';
import type {
  OccupancySummary,
  PatientFlowAmbient,
  PatientFlowFilters,
  PatientFlowSummary,
  PatientLayerState,
} from '@/features/patientFlowNavigator/types';
import type { RunStatus } from '@/features/virtualRounds/types';
// One label map for run states — the 4D HUD and the Rounds board must never
// word the same status differently.
import { RUN_STATUS_LABEL } from '@/Components/VirtualRounds/format';
import { formatDurationMinutes } from '@/lib/duration';

export interface RoundsHudModel {
  status: RunStatus;
  scopeLabel: string | null;
  total: number;
  rounded: number;
  awaitingInput: number;
}

export interface NavigatorMetrics {
  active: number;
  events: number;
  occupiedLocations: number;
}

export interface LayerControl {
  key: keyof PatientLayerState;
  label: string;
  id: string;
  /** Optional tooltip disambiguating the layer (e.g. Barriers vs Delayed). */
  title?: string;
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
  onFocusDelayed: () => void;
  onAskEddy: () => void;
  onSpeedChange: (speed: number) => void;
  onFiltersChange: (patch: Partial<PatientFlowFilters>) => void;
  /** Explicit floor choice: filters AND frames the floor (N-4). */
  onFloorSelect: (floor: string) => void;
  onLayerChange: (key: keyof PatientLayerState, value: boolean) => void;
  onBarrierFinderChange: (value: boolean) => void;
  /** Token count for the active search, null when the field is empty (N-5). */
  searchMatches: number | null;
  /** Enter in Find — fly to the matched tokens (N-5). */
  onSearchSubmit: () => void;
  /** Which saved-view slots hold a view (N-7). */
  savedViews: boolean[];
  onSaveView: (slot: number) => void;
  onApplyView: (slot: number) => void;
  /** Virtual Rounds run HUD + tour controls (R-5/R-6a); null → no run. */
  roundsHud: RoundsHudModel | null;
  tourAuto: boolean;
  onTourPrev: () => void;
  onTourNext: () => void;
  onTourAutoToggle: () => void;
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
  onFocusDelayed,
  onAskEddy,
  onSpeedChange,
  onFiltersChange,
  onFloorSelect,
  onLayerChange,
  onBarrierFinderChange,
  searchMatches,
  onSearchSubmit,
  savedViews,
  onSaveView,
  onApplyView,
  roundsHud,
  tourAuto,
  onTourPrev,
  onTourNext,
  onTourAutoToggle,
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

      {/* R-5: run HUD — colored by run state, never coral. Tour controls
          (R-6a) walk the itinerary; Auto pauses on any camera input. */}
      {roundsHud && (
        <div className={`patient-flow-rounds-hud run-${roundsHud.status}`} role="status">
          <div className="patient-flow-rounds-hud-text">
            <strong>
              Rounds · {RUN_STATUS_LABEL[roundsHud.status]}
              {roundsHud.scopeLabel ? ` · ${roundsHud.scopeLabel}` : ''}
            </strong>
            <span>
              {roundsHud.rounded}/{roundsHud.total} rounded
              {roundsHud.awaitingInput > 0 ? ` · ${roundsHud.awaitingInput} awaiting input` : ''}
            </span>
          </div>
          <div className="patient-flow-tour-controls">
            <button type="button" aria-label="Previous round stop" title="Previous round stop" onClick={onTourPrev}>◀</button>
            <button type="button" aria-label="Next round stop" title="Next round stop" onClick={onTourNext}>▶</button>
            <button
              type="button"
              aria-pressed={tourAuto}
              className={tourAuto ? 'active' : ''}
              title="Auto-step the tour every 10 seconds; moving the camera pauses it"
              onClick={onTourAutoToggle}
            >
              Auto
            </button>
          </div>
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
          title="Stream stored replay (not a live feed)"
          aria-label="Stream stored replay"
          aria-pressed={live}
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

      {/* N-7: three persona-keyed camera bookmarks — restore on the left,
          save-current on the right of each chip. */}
      <div className="patient-flow-views" aria-label="Saved views">
        {savedViews.map((hasView, slot) => (
          <div className="patient-flow-view-chip" key={`view-slot-${slot}`}>
            <button
              type="button"
              disabled={!hasView}
              title={hasView ? `Restore view ${slot + 1} (camera, floor, layers)` : `View ${slot + 1} is empty — save one first`}
              onClick={() => onApplyView(slot)}
            >
              View {slot + 1}
            </button>
            <button
              type="button"
              aria-label={`Save current view to slot ${slot + 1}`}
              title={hasView ? `Overwrite view ${slot + 1} with the current view` : `Save the current view as view ${slot + 1}`}
              onClick={() => onSaveView(slot)}
            >
              <Bookmark aria-hidden="true" />
            </button>
          </div>
        ))}
      </div>

      <div className="patient-flow-control-grid">
        <label htmlFor="flow-floor">Floor</label>
        <select
          id="flow-floor"
          value={filters.floor}
          onChange={(event) => onFloorSelect(event.target.value)}
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
          <option value={15}>{formatDurationMinutes(15)} / sec</option>
          <option value={60}>{formatDurationMinutes(60)} / sec</option>
          <option value={240}>{formatDurationMinutes(240)} / sec</option>
          <option value={720}>{formatDurationMinutes(720)} / sec</option>
        </select>

        <label htmlFor="flow-search">Find</label>
        <input
          id="flow-search"
          type="search"
          placeholder="PT, bed, service"
          value={filters.search}
          aria-describedby={searchMatches !== null ? 'flow-search-count' : undefined}
          onChange={(event) => onFiltersChange({ search: event.target.value })}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              onSearchSubmit();
            } else if (event.key === 'Escape') {
              onFiltersChange({ search: '' });
            }
          }}
        />

        {searchMatches !== null && (
          <small id="flow-search-count" className="patient-flow-search-count" role="status">
            {searchMatches === 0
              ? '0 matches — check spelling or floor filter'
              : `${searchMatches} ${searchMatches === 1 ? 'match' : 'matches'} · Enter flies to them`}
          </small>
        )}

        {/* Census scope, not a layer (B-2): "Delayed" filters the occupancy
            disks by elapsed-timer signal — distinct from the "Barriers"
            layer's logged prod.barriers markers (B-1). A span, not a label:
            the radios carry their own names (All / Delayed). */}
        <span className="patient-flow-control-caption">Census</span>
        <div
          className="patient-flow-census-toggle"
          role="radiogroup"
          aria-label="Census scope"
          title="Elapsed occupancy signal; not a verified operational barrier"
        >
          <label className={barrierFinder ? '' : 'active'}>
            <input
              id="flow-census-all"
              type="radio"
              name="flow-census"
              checked={!barrierFinder}
              onChange={() => onBarrierFinderChange(false)}
            />
            All
          </label>
          <label className={barrierFinder ? 'active' : ''}>
            <input
              id="flow-census-delayed"
              type="radio"
              name="flow-census"
              checked={barrierFinder}
              onChange={() => onBarrierFinderChange(true)}
            />
            Delayed
          </label>
        </div>
      </div>

      <fieldset className="patient-flow-layer-grid">
        <legend>Layers</legend>
        {layerControls.map(({ key, label, id, title }) => (
          <div className="patient-flow-checkbox-row" key={key}>
            <input
              id={id}
              type="checkbox"
              role="switch"
              checked={layers[key]}
              onChange={(event) => onLayerChange(key, event.target.checked)}
            />
            <label htmlFor={id} title={title}>{label}</label>
          </div>
        ))}
      </fieldset>

      {/* B-3: the Delayed-only census scope announces itself and relabels the
          metric — and the camera flight is an explicit action (B-4). */}
      {barrierFinder && (
        <div className="patient-flow-filter-chip" role="status">
          <span>Filtered: delayed locations only ({metrics.active})</span>
          <button
            type="button"
            title="Fly the camera to the delayed locations"
            onClick={onFocusDelayed}
          >
            Focus
          </button>
          <button
            type="button"
            aria-label="Clear delayed-only filter"
            title="Clear delayed-only filter"
            onClick={() => onBarrierFinderChange(false)}
          >
            ×
          </button>
        </div>
      )}

      <div className="patient-flow-metrics">
        <div><span>{metrics.active}</span><small>{barrierFinder ? 'Delayed' : 'Active'}</small></div>
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
