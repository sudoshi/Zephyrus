"""Object-centric Petri-net discovery (Part X, Phase XO.2).

Clean-room (see arena/CLEAN-ROOM.md). pm4py mines the OC Petri net; this module
serializes each per-object-type subnet into a flat JSON contract the React Study
UI renders — places (with initial/final markers), transitions (silent when the
label is null), and arcs (variable arcs mark synchronization across object
counts). Read-only, PHI-free: activity labels + de-identified object types only.

pm4py shape note (verified empirically against 2.7.23.1):
  discover_oc_petri_net(ocel) -> OCPetriNet (dict-like, NOT a plain dict)
  ocpn["petri_nets"]           -> dict[str, tuple[PetriNet, Marking, Marking]]
  ocpn["double_arcs_on_activity"] -> dict[str, dict[str, bool]]
                                     outer key = object_type
                                     inner key = activity label, value = bool
  Marking iterates directly over Place objects.
"""

from __future__ import annotations

from typing import Any

from app.ocel_loader import read_ocel

try:
    import pm4py  # type: ignore
except Exception:  # pragma: no cover
    pm4py = None  # type: ignore


def _discover_ocpn(ocel: Any) -> Any:
    """Call pm4py's OC Petri-net miner, tolerating signature drift across the 2.7 line."""
    if pm4py is None:  # pragma: no cover - the route guards with _require_engine first
        raise RuntimeError("pm4py is unavailable in this sidecar build")
    try:
        return pm4py.discover_oc_petri_net(ocel, noise_threshold=0.0)  # type: ignore[union-attr]
    except TypeError:
        return pm4py.discover_oc_petri_net(ocel)  # type: ignore[union-attr]


def _serialize_net(
    net: Any,
    im: Any,
    fm: Any,
    object_type: str,
    double_arcs: dict[str, Any],
) -> dict[str, Any]:
    """Serialize one per-object-type PetriNet + marking triple to our flat JSON contract.

    Marking objects iterate over Place objects (not strings), so initial/final sets
    are built by collecting the Place objects yielded by iterating im/fm.
    """
    initial: set[str] = {p.name for p in (im or [])}
    final: set[str] = {p.name for p in (fm or [])}

    # double_arcs_on_activity outer key = object_type, inner key = activity label
    ot_double: dict[str, bool] = double_arcs.get(object_type, {})

    places = [
        {"id": p.name, "initial": p.name in initial, "final": p.name in final}
        for p in net.places
    ]

    transitions = [{"id": t.name, "label": t.label} for t in net.transitions]

    def _arc_is_variable(arc: Any) -> bool:
        # Exactly one arc endpoint is a Transition (has .label); the other is a
        # Place (no .label). A variable arc is one whose transition activity is
        # marked True in double_arcs_on_activity for this object type — i.e. the
        # transition is shared across multiple object counts.
        label = getattr(arc.source, "label", None) or getattr(arc.target, "label", None)
        return bool(ot_double.get(label, False)) if label is not None else False

    arcs = [
        {
            "source": a.source.name,
            "target": a.target.name,
            "variable": _arc_is_variable(a),
            "weight": int(getattr(a, "weight", 1) or 1),
        }
        for a in net.arcs
    ]

    return {
        "object_type": object_type,
        "places": places,
        "transitions": transitions,
        "arcs": arcs,
    }


def discover(path: str, filters: list[Any] | None = None) -> dict[str, Any]:
    """Discover and serialize the object-centric Petri net for the OCEL log at *path*.

    Returns a canonical JSON-serializable dict with three top-level keys:
      object_types  — sorted list of object type names present in the net
      nets          — list of per-object-type subnet dicts (places/transitions/arcs)
      stats         — summary counts (object_types, places, transitions, arcs)
    """
    ocel = read_ocel(path)
    if filters:
        from app.filters import apply_filters  # optional XO.1 dependency

        ocel = apply_filters(ocel, filters)

    ocpn = _discover_ocpn(ocel)

    # OCPetriNet is dict-like (supports __getitem__ / keys() / items()) but is
    # NOT a plain dict — ocpn.get() is also available via the __legacy_dict mixin.
    petri_nets: dict[str, Any] = ocpn["petri_nets"] if "petri_nets" in ocpn else {}
    double_arcs: dict[str, Any] = (
        ocpn["double_arcs_on_activity"] if "double_arcs_on_activity" in ocpn else {}
    )

    nets: list[dict[str, Any]] = []
    for ot, triple in petri_nets.items():
        try:
            net, im, fm = triple
        except (TypeError, ValueError):
            continue
        nets.append(_serialize_net(net, im, fm, str(ot), double_arcs))

    return {
        "object_types": sorted(n["object_type"] for n in nets),
        "nets": nets,
        "stats": {
            "object_types": len(nets),
            "places": sum(len(n["places"]) for n in nets),
            "transitions": sum(len(n["transitions"]) for n in nets),
            "arcs": sum(len(n["arcs"]) for n in nets),
        },
    }
