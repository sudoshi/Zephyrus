#!/usr/bin/env python3
"""Local API and static server for the 3D/4D patient-flow navigator."""

from __future__ import annotations

import json
import mimetypes
import time
from types import SimpleNamespace
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

from flow_engine import (
    flow_event_to_fhir_bundle,
    iso_to_dt,
    occupancy_by_location,
    parse_hl7_v2_message,
    read_ndjson,
    reconstruct_patient_state,
)

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
VIEWER = ROOT / "viewer"
CAD_ROOT = ROOT.parent / "hospital-cad-model"
LIVE_EVENTS: list[dict] = []


def load_events() -> list[dict]:
    return read_ndjson(DATA / "normalized_events.ndjson")


def load_locations() -> dict:
    return json.loads((DATA / "location_index.json").read_text(encoding="utf-8"))


def load_summary() -> dict:
    return json.loads((DATA / "summary.json").read_text(encoding="utf-8"))


def parse_json_body(handler: BaseHTTPRequestHandler) -> dict | str:
    length = int(handler.headers.get("Content-Length", "0"))
    body = handler.rfile.read(length).decode("utf-8") if length else ""
    ctype = handler.headers.get("Content-Type", "")
    if "application/json" in ctype:
        return json.loads(body or "{}")
    return body


def filter_events(events: list[dict], query: dict[str, list[str]]) -> list[dict]:
    start = query.get("from", [None])[0]
    end = query.get("to", [None])[0]
    patient = query.get("patient", [None])[0]
    event_category = query.get("category", [None])[0]
    service_line = query.get("service_line", [None])[0]
    floor = query.get("floor", [None])[0]
    filtered = []
    start_dt = iso_to_dt(start) if start else None
    end_dt = iso_to_dt(end) if end else None
    for event in events:
        when = iso_to_dt(event["occurred_at"])
        if start_dt and when < start_dt:
            continue
        if end_dt and when > end_dt:
            continue
        if patient and event.get("patient_id") != patient and event.get("patient_display_id") != patient:
            continue
        if event_category and event.get("event_category") != event_category:
            continue
        if service_line and event.get("service_line") != service_line:
            continue
        if floor and str(event.get("location_floor")) != floor:
            continue
        filtered.append(event)
    limit = int(query.get("limit", ["5000"])[0])
    return filtered[:limit]


def group_tracks(events: list[dict]) -> dict[str, list[dict]]:
    tracks: dict[str, list[dict]] = {}
    for event in events:
        tracks.setdefault(event["patient_id"], []).append(event)
    return tracks


def static_path_for(url_path: str) -> Path | None:
    if url_path in {"/", ""}:
        return VIEWER / "index.html"
    if url_path in {"/viewer", "/viewer/"}:
        return VIEWER / "index.html"
    if url_path.startswith("/viewer/"):
        return VIEWER / unquote(url_path.removeprefix("/viewer/"))
    if url_path.startswith("/data/"):
        return DATA / unquote(url_path.removeprefix("/data/"))
    if url_path.startswith("/cad-model/"):
        return CAD_ROOT / unquote(url_path.removeprefix("/cad-model/"))
    return None


class FlowNavigatorHandler(BaseHTTPRequestHandler):
    server_version = "PatientFlow4D/0.1"

    def end_headers(self) -> None:
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        super().end_headers()

    def send_json(self, data: dict | list, status: int = 200) -> None:
        body = json.dumps(data, separators=(",", ":"), sort_keys=True).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_text(self, text: str, status: int = 200, ctype: str = "text/plain; charset=utf-8") -> None:
        body = text.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self) -> None:
        self.send_response(HTTPStatus.NO_CONTENT)
        self.end_headers()

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        query = parse_qs(parsed.query)
        route = parsed.path
        events = load_events() + LIVE_EVENTS

        try:
            if route == "/api/summary":
                summary = load_summary()
                summary["live_events"] = len(LIVE_EVENTS)
                self.send_json(summary)
                return
            if route == "/api/locations":
                self.send_json(load_locations())
                return
            if route == "/api/events":
                self.send_json(filter_events(events, query))
                return
            if route == "/api/tracks":
                self.send_json(group_tracks(filter_events(events, query)))
                return
            if route == "/api/state":
                as_of = query.get("asOf", [None])[0]
                state = reconstruct_patient_state(events, as_of)
                self.send_json({
                    "asOf": as_of,
                    "activePatients": len(state),
                    "patients": list(state.values()),
                    "occupancy": occupancy_by_location(events, as_of),
                })
                return
            if route == "/api/fhir/bundle":
                event_id = query.get("event_id", [None])[0]
                event = next((item for item in events if item["event_id"] == event_id), None)
                if not event:
                    self.send_json({"error": "event_id not found"}, 404)
                    return
                self.send_json(flow_event_to_fhir_bundle(SimpleNamespace(**event)))
                return
            if route == "/stream/adt":
                self.stream_events(filter_events(events, query), query)
                return
            static = static_path_for(route)
            if static and static.is_file():
                self.serve_file(static)
                return
            self.send_json({"error": "not found", "path": route}, 404)
        except Exception as exc:  # pragma: no cover - local dev surface
            self.send_json({"error": type(exc).__name__, "detail": str(exc)}, 500)

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path != "/api/hl7v2":
            self.send_json({"error": "not found"}, 404)
            return
        try:
            body = parse_json_body(self)
            raw = body.get("raw_hl7", "") if isinstance(body, dict) else str(body)
            if not raw.strip():
                self.send_json({"error": "raw_hl7 body is required"}, 400)
                return
            event = parse_hl7_v2_message(raw, source_protocol="hl7v2-post").to_dict()
            locations = load_locations()
            loc = locations.get(event.get("to_location") or "")
            if loc:
                event["location_name"] = loc["name"]
                event["location_category"] = loc["category"]
                event["location_floor"] = loc["floor"]
                event["position_ft"] = loc["position_ft"]
                event["position_m"] = loc["position_m"]
                event["unit_code"] = loc.get("metadata", {}).get("unit_code")
            LIVE_EVENTS.append(event)
            self.send_json({"accepted": True, "event": event}, 202)
        except Exception as exc:
            self.send_json({"error": type(exc).__name__, "detail": str(exc)}, 400)

    def serve_file(self, path: Path) -> None:
        ctype, _ = mimetypes.guess_type(str(path))
        data = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", ctype or "application/octet-stream")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def stream_events(self, events: list[dict], query: dict[str, list[str]]) -> None:
        speed = max(0.05, float(query.get("interval", ["1.0"])[0]))
        replay = events[-int(query.get("replay", ["160"])[0]):]
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.send_header("Connection", "keep-alive")
        self.end_headers()
        self.wfile.write(b": connected\n\n")
        self.wfile.flush()
        for index, event in enumerate(replay, start=1):
            payload = dict(event)
            payload["stream_sequence"] = index
            payload["streamed_at_epoch_ms"] = int(time.time() * 1000)
            message = f"id: {payload['event_id']}\nevent: patient-flow\ndata: {json.dumps(payload, separators=(',', ':'))}\n\n"
            self.wfile.write(message.encode("utf-8"))
            self.wfile.flush()
            time.sleep(speed)

    def log_message(self, fmt: str, *args) -> None:
        print("[%s] %s" % (self.log_date_time_string(), fmt % args))


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser(description="Run the 3D/4D patient-flow navigator server.")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8776)
    args = parser.parse_args()
    server = ThreadingHTTPServer((args.host, args.port), FlowNavigatorHandler)
    print(f"Serving patient-flow navigator on http://{args.host}:{args.port}/viewer/")
    server.serve_forever()


if __name__ == "__main__":
    main()
