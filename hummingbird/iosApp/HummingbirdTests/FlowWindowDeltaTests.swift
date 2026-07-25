import Foundation
import XCTest
@testable import Hummingbird

@MainActor
final class FlowWindowDeltaTests: XCTestCase {
    private let t1 = "2026-07-04T10:00:00+00:00"
    private let t2 = "2026-07-04T11:00:00+00:00"
    private let t3 = "2026-07-04T12:00:00+00:00"

    override func tearDown() {
        FlowWindowDeltaURLProtocol.handler = nil
        super.tearDown()
    }

    func testDeltaMergeAppendsOnlyNewHistoricalItemsAndReplacesCurrentState() {
        let current = window(
            now: t2,
            events: [event(t1, "admit", "Admitted"), event(t2, "bed_request", "Bed request", ref: "ref_a")],
            snapshots: [snapshot(t1, unitID: 1), snapshot(t1, unitID: 2)],
            projections: [projection("surge_probability")],
            bedStatuses: [bedStatus(1, "dirty")],
            duties: [duty("transport_run")],
            spaces: spaces(floor: 1)
        )
        let delta = window(
            now: t3,
            since: t2,
            events: [
                event(t2, "bed_request", "Bed request", ref: "ref_a"),
                event(t2, "bed_request", "Bed request", ref: "ref_b"),
                event(t3, "transfer", "Transfer"),
            ],
            snapshots: [snapshot(t1, unitID: 1), snapshot(t2, unitID: 1)],
            projections: [projection("expected_discharge")],
            bedStatuses: [bedStatus(3, "available")],
            duties: [duty("bed_turn")],
            spaces: spaces(floor: 9)
        )

        let merged = current.merged(delta: delta)

        XCTAssertEqual(merged.events.map(\.kind), ["admit", "bed_request", "bed_request", "transfer"])
        XCTAssertEqual(Set(merged.events.compactMap { $0.entity?.ref }), ["ref_a", "ref_b"])
        XCTAssertEqual(merged.snapshots.count, 3)
        XCTAssertEqual(merged.projections.map(\.kind), ["expected_discharge"])
        XCTAssertEqual(merged.bedStatuses.map(\.bedId), [3])
        XCTAssertEqual(merged.duties.map(\.kind), ["bed_turn"])
        XCTAssertEqual(merged.spaces?.floors.map(\.floor), [9])
        XCTAssertEqual(merged.window.now, t3)
        XCTAssertEqual(merged.window.since, t2)
    }

    func testDeltaPreservesCurrentSpacesOnlyWhenServerOmitsThatLayer() {
        let current = window(now: t2, spaces: spaces(floor: 4))
        let delta = window(now: t3, since: t2, spaces: nil)

        XCTAssertEqual(current.merged(delta: delta).spaces?.floors.map(\.floor), [4])
    }

    func testFlowWindowSendsTheExactVersionCursorAndDecodesItsEcho() async throws {
        var capturedRequest: URLRequest?
        FlowWindowDeltaURLProtocol.handler = { request in
            capturedRequest = request
            return (200, Data(Self.deltaEnvelope.utf8))
        }

        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [FlowWindowDeltaURLProtocol.self]
        configuration.urlCache = nil
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCredentialStorage = nil
        let client = APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
        )

        let envelope = try await client.flowWindow(
            persona: "bed_manager",
            scope: "house",
            since: t2,
            bearer: "staff-token"
        )

        XCTAssertEqual(envelope.data.window.since, t2)
        let request = try XCTUnwrap(capturedRequest)
        XCTAssertEqual(request.httpMethod, "GET")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
        let queryItems = try XCTUnwrap(URLComponents(url: try XCTUnwrap(request.url), resolvingAgainstBaseURL: false)?.queryItems)
        XCTAssertEqual(queryItems.first(where: { $0.name == "persona" })?.value, "bed_manager")
        XCTAssertEqual(queryItems.first(where: { $0.name == "scope" })?.value, "house")
        XCTAssertEqual(queryItems.first(where: { $0.name == "since" })?.value, t2)
    }

    private func window(
        now: String,
        since: String? = nil,
        events: [FlowTimelineEvent] = [],
        snapshots: [FlowSnapshot] = [],
        projections: [FlowProjection] = [],
        bedStatuses: [FlowBedStatus] = [],
        duties: [FlowDuty] = [],
        spaces: FlowSpaces? = nil
    ) -> FlowWindowData {
        FlowWindowData(
            window: FlowWindow(from: t1, to: t3, now: now, since: since),
            lens: FlowLens(
                roleId: "bed_manager",
                scopeDefault: "house",
                scopesAllowed: ["house"],
                layers: ["events", "snapshots", "projections", "duties"],
                eventKinds: [],
                projectionKinds: [],
                patientDots: "full",
                actions: [],
                defaultZoomHours: 48
            ),
            scope: FlowScope(type: "house", floor: nil, unitId: nil, patientContextRef: nil, label: "House"),
            spaces: spaces,
            snapshots: snapshots,
            events: events,
            projections: projections,
            bedStatuses: bedStatuses,
            duties: duties
        )
    }

    private func event(_ time: String, _ kind: String, _ label: String, ref: String? = nil) -> FlowTimelineEvent {
        FlowTimelineEvent(
            t: time,
            kind: kind,
            entity: ref.map { FlowEntityRef(type: "event", ref: $0) },
            patientContextRef: nil,
            fromSpace: nil,
            toSpace: nil,
            unitId: 1,
            label: label,
            tier: "info",
            provenance: nil
        )
    }

    private func snapshot(_ time: String, unitID: Int) -> FlowSnapshot {
        FlowSnapshot(t: time, unitId: unitID, occupied: 5, staffed: 10, available: 5, blocked: 0, occupancyPct: 50)
    }

    private func projection(_ kind: String) -> FlowProjection {
        FlowProjection(
            t: t3,
            kind: kind,
            confidence: "probable",
            unitId: 1,
            bedId: nil,
            room: nil,
            entity: nil,
            patientContextRef: nil,
            label: kind,
            value: nil,
            band: nil,
            endsAt: nil,
            derived: false,
            provenance: nil
        )
    }

    private func bedStatus(_ bedID: Int, _ status: String) -> FlowBedStatus {
        FlowBedStatus(bedId: bedID, unitId: 1, label: "MICU-0\(bedID)", status: status)
    }

    private func duty(_ kind: String) -> FlowDuty {
        FlowDuty(
            id: "duty-\(kind)",
            kind: kind,
            label: kind,
            spaceRef: nil,
            unitId: 1,
            bedId: nil,
            centroidM: nil,
            dueAt: nil,
            windowStatus: "upcoming",
            tier: "info",
            patientContextRef: nil,
            action: nil
        )
    }

    private func spaces(floor: Int) -> FlowSpaces {
        FlowSpaces(platesVersion: "plates-v\(floor)", floors: [
            FlowFloorRollup(
                floor: floor,
                label: "Floor \(floor)",
                units: [],
                staffed: 10,
                occupied: 5,
                available: 5,
                blocked: 0,
                occupancyPct: 50,
                evsOpen: 0,
                transportActive: 0,
                barriersOpen: 0
            ),
        ])
    }

    private static let deltaEnvelope = #"""
    {
      "data": {
        "window": {
          "from": "2026-07-04T10:00:00+00:00",
          "to": "2026-07-04T12:00:00+00:00",
          "now": "2026-07-04T12:00:00+00:00",
          "since": "2026-07-04T11:00:00+00:00"
        },
        "lens": {
          "role_id": "bed_manager",
          "scope_default": "house",
          "scopes_allowed": ["house"],
          "layers": ["events", "snapshots", "projections", "duties"],
          "event_kinds": [],
          "projection_kinds": [],
          "patient_dots": "full",
          "actions": [],
          "default_zoom_hours": 48
        },
        "scope": {"type": "house", "label": "House"},
        "events": [],
        "snapshots": [],
        "projections": [],
        "bed_statuses": [],
        "duties": []
      },
      "meta": {},
      "links": {}
    }
    """#
}

private final class FlowWindowDeltaURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> (Int, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        do {
            let (status, data) = try XCTUnwrap(Self.handler)(request)
            let response = HTTPURLResponse(
                url: try XCTUnwrap(request.url),
                statusCode: status,
                httpVersion: "HTTP/1.1",
                headerFields: ["Content-Type": "application/json"]
            )!
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}
