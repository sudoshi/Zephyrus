"""Filter engine — clean-room mask-based OCEL filtering (Phase XO.1).

Builds a tiny in-memory pm4py OCEL fixture (no file IO) and asserts each filter
plus the composition/pruning semantics: an event that loses all its objects is
dropped, object filters prune relations, and filters AND-compose.
"""

from __future__ import annotations

import pandas as pd
import pytest

pm4py = pytest.importorskip("pm4py")
from pm4py.objects.ocel.obj import OCEL as PMOCEL  # noqa: E402

from app.filters import (  # noqa: E402
    EventAttributeFilter,
    EventTypeFilter,
    ObjectTypeFilter,
    TimeFrameFilter,
    apply_filters,
    parse_filters,
)

OCEL_EID = "ocel:eid"
OCEL_OID = "ocel:oid"
OCEL_ACTIVITY = "ocel:activity"
OCEL_TYPE = "ocel:type"
OCEL_TIME = "ocel:timestamp"


def _toy_ocel() -> PMOCEL:
    ts = pd.to_datetime(
        ["2026-01-01T00:00:00Z", "2026-01-01T01:00:00Z", "2026-01-01T02:00:00Z"], utc=True
    )
    events = pd.DataFrame(
        {
            OCEL_EID: ["e1", "e2", "e3"],
            OCEL_ACTIVITY: ["triage", "admit", "discharge"],
            OCEL_TIME: ts,
            "acuity": ["ESI-2", "ESI-2", "ESI-4"],
        }
    )
    objects = pd.DataFrame(
        {OCEL_OID: ["enc1", "pat1", "bed1"], OCEL_TYPE: ["Encounter", "Patient", "Bed"]}
    )
    relations = pd.DataFrame(
        {
            OCEL_EID: ["e1", "e1", "e2", "e2", "e3", "e3"],
            OCEL_OID: ["enc1", "pat1", "enc1", "bed1", "enc1", "bed1"],
            OCEL_TYPE: ["Encounter", "Patient", "Encounter", "Bed", "Encounter", "Bed"],
            OCEL_ACTIVITY: ["triage", "triage", "admit", "admit", "discharge", "discharge"],
            OCEL_TIME: [ts[0], ts[0], ts[1], ts[1], ts[2], ts[2]],
            "ocel:qualifier": ["subject", "subject", "subject", "location", "subject", "location"],
        }
    )
    return PMOCEL(events=events, objects=objects, relations=relations)


def test_object_type_exclude_prunes_relations_but_keeps_events():
    ocel = apply_filters(_toy_ocel(), [ObjectTypeFilter(object_types=["Bed"], mode="exclude")])
    assert set(ocel.objects[OCEL_OID]) == {"enc1", "pat1"}
    assert "bed1" not in set(ocel.relations[OCEL_OID])
    assert set(ocel.events[OCEL_EID]) == {"e1", "e2", "e3"}


def test_event_type_include_keeps_only_matching_events():
    ocel = apply_filters(_toy_ocel(), [EventTypeFilter(activities=["triage"], mode="include")])
    assert set(ocel.events[OCEL_EID]) == {"e1"}
    assert set(ocel.relations[OCEL_EID]) == {"e1"}


def test_time_frame_start_keeps_later_events():
    ocel = apply_filters(_toy_ocel(), [TimeFrameFilter(start="2026-01-01T01:00:00Z")])
    assert set(ocel.events[OCEL_EID]) == {"e2", "e3"}


def test_event_attribute_include():
    ocel = apply_filters(_toy_ocel(), [EventAttributeFilter(name="acuity", values=["ESI-4"])])
    assert set(ocel.events[OCEL_EID]) == {"e3"}


def test_filters_and_compose():
    ocel = apply_filters(
        _toy_ocel(),
        [
            EventTypeFilter(activities=["admit", "discharge"], mode="include"),
            ObjectTypeFilter(object_types=["Bed"], mode="exclude"),
        ],
    )
    assert set(ocel.events[OCEL_EID]) == {"e2", "e3"}
    assert "bed1" not in set(ocel.objects[OCEL_OID])


def test_parse_filters_builds_typed_filters():
    filters = parse_filters(
        [
            {"kind": "object_type", "object_types": ["Bed"], "mode": "exclude"},
            {"kind": "time_frame", "start": "2026-01-01T01:00:00Z"},
        ]
    )
    assert isinstance(filters[0], ObjectTypeFilter)
    assert isinstance(filters[1], TimeFrameFilter)


def test_parse_filters_rejects_unknown_kind():
    with pytest.raises(ValueError):
        parse_filters([{"kind": "nonsense"}])


def _toy_ocel_with_graph() -> PMOCEL:
    base = _toy_ocel()
    o2o = pd.DataFrame(
        {
            "ocel:oid": ["enc1", "enc1"],
            "ocel:oid_2": ["pat1", "bed1"],
            "ocel:qualifier": ["of", "occupies"],
        }
    )
    object_changes = pd.DataFrame(
        {
            "ocel:oid": ["bed1", "enc1"],
            "ocel:type": ["Bed", "Encounter"],
            "ocel:timestamp": pd.to_datetime(
                ["2026-01-01T01:00:00Z", "2026-01-01T00:30:00Z"], utc=True
            ),
            "ocel:field": ["status", "acuity"],
        }
    )
    return PMOCEL(
        events=base.events,
        objects=base.objects,
        relations=base.relations,
        o2o=o2o,
        object_changes=object_changes,
    )


def test_filter_prunes_o2o_and_object_changes_to_surviving_objects():
    ocel = apply_filters(
        _toy_ocel_with_graph(), [ObjectTypeFilter(object_types=["Bed"], mode="exclude")]
    )
    # bed1 dropped => its o2o edge (enc1->bed1) and its object_change row must be pruned
    assert set(ocel.o2o["ocel:oid_2"]) == {"pat1"}
    assert "bed1" not in set(ocel.object_changes["ocel:oid"])
    assert "enc1" in set(ocel.object_changes["ocel:oid"])
