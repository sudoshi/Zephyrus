"""Composable, mask-based OCEL filtering (Part X, Phase XO.1).

Clean-room reimplementation (see arena/CLEAN-ROOM.md): each filter produces a
boolean mask over the pm4py OCEL's `events` / `objects` DataFrames. Masks
AND-combine; `apply_filters` slices the frames, prunes E2O relations to the
surviving events and objects, drops any event left touching no object (the OCEL
invariant the exporter validates), and rebuilds a pm4py OCEL. Read-only and
PHI-free — filters operate on de-identified ids, activity labels, timestamps and
event attributes only.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Literal, Optional, Sequence

import pandas as pd
from pm4py.objects.ocel.obj import OCEL as PMOCEL
from pydantic import BaseModel

OCEL_EID = "ocel:eid"
OCEL_OID = "ocel:oid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TYPE = "ocel:type"
OCEL_TIME = "ocel:timestamp"
OCEL_OID2 = "ocel:oid_2"
OCEL_EID2 = "ocel:eid_2"


@dataclass
class FilterResult:
    """A partial verdict: an events mask, an objects mask, or either. `None` means
    the filter is silent on that dimension (treated as all-True when combined)."""

    events: Optional[pd.Series] = None
    objects: Optional[pd.Series] = None


class BaseFilter(ABC, BaseModel):
    @abstractmethod
    def mask(self, ocel: PMOCEL) -> FilterResult: ...


class ObjectTypeFilter(BaseFilter):
    kind: Literal["object_type"] = "object_type"
    object_types: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        m = ocel.objects[OCEL_TYPE].isin(self.object_types)
        return FilterResult(objects=~m if self.mode == "exclude" else m)


class EventTypeFilter(BaseFilter):
    kind: Literal["event_type"] = "event_type"
    activities: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        m = ocel.events[OCEL_ACTIVITY].isin(self.activities)
        return FilterResult(events=~m if self.mode == "exclude" else m)


def _to_utc(value: datetime | str) -> pd.Timestamp:
    ts = pd.Timestamp(value)
    return ts.tz_localize("UTC") if ts.tzinfo is None else ts.tz_convert("UTC")


class TimeFrameFilter(BaseFilter):
    kind: Literal["time_frame"] = "time_frame"
    start: Optional[datetime] = None
    end: Optional[datetime] = None

    def mask(self, ocel: PMOCEL) -> FilterResult:
        ts = pd.to_datetime(ocel.events[OCEL_TIME], utc=True)
        m = pd.Series(True, index=ocel.events.index)
        if self.start is not None:
            m &= ts >= _to_utc(self.start)
        if self.end is not None:
            m &= ts <= _to_utc(self.end)
        return FilterResult(events=m)


class EventAttributeFilter(BaseFilter):
    kind: Literal["event_attribute"] = "event_attribute"
    name: str
    values: list[str]
    mode: Literal["include", "exclude"] = "include"

    def mask(self, ocel: PMOCEL) -> FilterResult:
        events = ocel.events
        if self.name not in events.columns:
            base = pd.Series(False, index=events.index)
        else:
            base = events[self.name].astype("string").isin(self.values)
        return FilterResult(events=~base if self.mode == "exclude" else base)


_FILTERS: dict[str, type[BaseFilter]] = {
    "object_type": ObjectTypeFilter,
    "event_type": EventTypeFilter,
    "time_frame": TimeFrameFilter,
    "event_attribute": EventAttributeFilter,
}


def parse_filters(specs: Sequence[dict[str, Any]] | None) -> list[BaseFilter]:
    """Build typed filters from request dicts, discriminated by `kind`."""
    out: list[BaseFilter] = []
    for spec in specs or []:
        cls = _FILTERS.get(str(spec.get("kind")))
        if cls is None:
            raise ValueError(f"unknown filter kind: {spec.get('kind')!r}")
        out.append(cls.model_validate(spec))
    return out


def apply_filters(ocel: PMOCEL, filters: Sequence[BaseFilter] | None) -> PMOCEL:
    """Return a new pm4py OCEL with the AND of all filter masks applied.

    In addition to slicing events/objects/relations, the optional OCEL 2.0 frames
    are pruned to the surviving ids: o2o edges where either endpoint was removed,
    object_changes for removed objects, and e2e edges where either event was
    removed are all dropped. The globals dict is carried through unchanged.
    """
    if not filters:
        return ocel

    events, objects, relations = ocel.events, ocel.objects, ocel.relations
    ev_mask = pd.Series(True, index=events.index)
    ob_mask = pd.Series(True, index=objects.index)

    for f in filters:
        res = f.mask(ocel)
        if res.events is not None:
            ev_mask &= res.events.reindex(events.index, fill_value=False)
        if res.objects is not None:
            ob_mask &= res.objects.reindex(objects.index, fill_value=False)

    kept_oids = set(objects.loc[ob_mask, OCEL_OID])
    kept_eids_by_event = set(events.loc[ev_mask, OCEL_EID])

    rel = relations[
        relations[OCEL_OID].isin(kept_oids) & relations[OCEL_EID].isin(kept_eids_by_event)
    ]
    surviving_eids = set(rel[OCEL_EID])

    f_events = events[events[OCEL_EID].isin(surviving_eids)].reset_index(drop=True)
    f_objects = objects[ob_mask].reset_index(drop=True)
    f_relations = rel.reset_index(drop=True)

    f_o2o = ocel.o2o[
        ocel.o2o[OCEL_OID].isin(kept_oids) & ocel.o2o[OCEL_OID2].isin(kept_oids)
    ].reset_index(drop=True)
    f_object_changes = ocel.object_changes[
        ocel.object_changes[OCEL_OID].isin(kept_oids)
    ].reset_index(drop=True)
    f_e2e = ocel.e2e[
        ocel.e2e[OCEL_EID].isin(surviving_eids) & ocel.e2e[OCEL_EID2].isin(surviving_eids)
    ].reset_index(drop=True)

    return PMOCEL(
        events=f_events,
        objects=f_objects,
        relations=f_relations,
        globals=ocel.globals,
        o2o=f_o2o,
        e2e=f_e2e,
        object_changes=f_object_changes,
    )
