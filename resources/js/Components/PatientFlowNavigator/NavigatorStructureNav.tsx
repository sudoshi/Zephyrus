import React, { useCallback, useId, useMemo, useRef, useState } from 'react';
import type { PatientFlowLocations } from '@/features/patientFlowNavigator/types';
import {
  buildStructureTree,
  flattenVisible,
} from '@/features/patientFlowNavigator/structureTree';
import type { StructureNode } from '@/features/patientFlowNavigator/structureTree';

/**
 * Structure traversal (E3): a keyboard-walkable floor → unit → bed tree —
 * "how do I get to X" without the pointer, and the non-pointer path to a bed
 * selection (Data-Navigator pattern). Roving tabindex + arrow-key graph moves;
 * Enter selects a bed (through the SAME selectEntity seam the canvas uses) or
 * frames a floor/unit. Collapsed by default so it never crowds the wall.
 */

interface NavigatorStructureNavProps {
  locations: PatientFlowLocations;
  /** Select a bed by its location code — routes to the shared selectEntity path. */
  onSelectBed: (locationCode: string) => void;
  /** Frame a node's descendant bed positions (camera flight). */
  onFrame: (points: Array<{ x: number; y: number; z: number }>) => void;
}

function collectPositions(node: StructureNode): Array<{ x: number; y: number; z: number }> {
  if (node.kind === 'bed') return node.position ? [node.position] : [];
  return node.children.flatMap(collectPositions);
}

export default function NavigatorStructureNav({
  locations,
  onSelectBed,
  onFrame,
}: NavigatorStructureNavProps) {
  const tree = useMemo(() => buildStructureTree(locations), [locations]);
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set());
  const [focusedId, setFocusedId] = useState<string | null>(null);
  const rowRefs = useRef(new Map<string, HTMLButtonElement>());
  const headingId = useId();

  const rows = useMemo(() => flattenVisible(tree, expanded), [tree, expanded]);

  const focusRow = useCallback((id: string | null): void => {
    setFocusedId(id);
    if (id) window.requestAnimationFrame(() => rowRefs.current.get(id)?.focus());
  }, []);

  const toggleExpand = useCallback((id: string): void => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }, []);

  const activate = useCallback((node: StructureNode): void => {
    if (node.kind === 'bed') {
      // Frame the bed regardless (empty beds have no occupancy entity to
      // select); an occupied bed also selects through the shared seam.
      if (node.position) onFrame([node.position]);
      if (node.locationCode) onSelectBed(node.locationCode);
      return;
    }
    // Floor/unit: frame it AND expand so the walk continues downward.
    const points = collectPositions(node);
    if (points.length) onFrame(points);
    if (!expanded.has(node.id)) toggleExpand(node.id);
  }, [expanded, onFrame, onSelectBed, toggleExpand]);

  const onRowKeyDown = useCallback((event: React.KeyboardEvent, index: number): void => {
    const row = rows[index];
    if (!row) return;
    const { node } = row;
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        if (index < rows.length - 1) focusRow(rows[index + 1].node.id);
        break;
      case 'ArrowUp':
        event.preventDefault();
        if (index > 0) focusRow(rows[index - 1].node.id);
        break;
      case 'ArrowRight':
        event.preventDefault();
        if (node.children.length > 0) {
          if (!expanded.has(node.id)) toggleExpand(node.id);
          else focusRow(node.children[0].id);
        }
        break;
      case 'ArrowLeft':
        event.preventDefault();
        if (node.children.length > 0 && expanded.has(node.id)) {
          toggleExpand(node.id);
        } else {
          // Ascend to the parent row (the nearest shallower row above).
          for (let scan = index - 1; scan >= 0; scan -= 1) {
            if (rows[scan].depth < row.depth) { focusRow(rows[scan].node.id); break; }
          }
        }
        break;
      case 'Enter':
      case ' ':
        event.preventDefault();
        activate(node);
        break;
      default:
        break;
    }
  }, [activate, expanded, focusRow, rows, toggleExpand]);

  if (tree.length === 0) return null;

  const activeId = focusedId && rows.some((row) => row.node.id === focusedId)
    ? focusedId
    : rows[0]?.node.id ?? null;

  return (
    <section className="patient-flow-structure-nav" aria-labelledby={headingId}>
      <button
        type="button"
        className="patient-flow-structure-toggle"
        aria-expanded={open}
        id={headingId}
        onClick={() => setOpen((value) => !value)}
      >
        Structure <span aria-hidden="true">{open ? '▾' : '▸'}</span>
      </button>

      {open && (
        <ul role="tree" aria-label="Building structure" className="patient-flow-structure-tree">
          {rows.map((row, index) => (
            <li
              key={row.node.id}
              role="treeitem"
              aria-level={row.depth + 1}
              aria-expanded={row.expanded}
              aria-selected={row.node.id === activeId}
            >
              <button
                type="button"
                ref={(element) => {
                  if (element) rowRefs.current.set(row.node.id, element);
                  else rowRefs.current.delete(row.node.id);
                }}
                tabIndex={row.node.id === activeId ? 0 : -1}
                className={`patient-flow-structure-row kind-${row.node.kind}`}
                style={{ paddingLeft: `${8 + row.depth * 14}px` }}
                onKeyDown={(event) => onRowKeyDown(event, index)}
                onFocus={() => setFocusedId(row.node.id)}
                onClick={() => activate(row.node)}
              >
                {row.node.children.length > 0 && (
                  <span className="patient-flow-structure-caret" aria-hidden="true">
                    {row.expanded ? '▾' : '▸'}
                  </span>
                )}
                <span className="patient-flow-structure-label">{row.node.label}</span>
                {row.node.kind === 'unit' && (
                  <span className="patient-flow-structure-count">{row.node.children.length}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
