import XCTest
@testable import Hummingbird

final class ForYouPatientCommunicationModelTests: XCTestCase {
    func testExactCommunicationDecodesToFixedCopyAndDropsEveryOversharedField() throws {
        let item = try decode(Self.oversharedCommunication)

        XCTAssertTrue(item.isPatientCommunicationAttention)
        XCTAssertEqual(item.id, "patient-communication-11111111-1111-4111-8111-111111111111")
        XCTAssertEqual(item.patientCommunicationWorkItemUUID, "11111111-1111-4111-8111-111111111111")
        XCTAssertEqual(item.type, "patient_communication")
        XCTAssertEqual(item.domain, "communications")
        XCTAssertEqual(item.tier, "critical")
        XCTAssertEqual(item.visualStatus, "critical")
        XCTAssertEqual(item.title, "Escalated patient communication")
        XCTAssertEqual(item.subtitle, "Open the secure conversation to review the request.")
        XCTAssertEqual(item.unit, "5 East — Medical/Surgical")
        XCTAssertEqual(item.at, "2026-07-19T14:05:00Z")
        XCTAssertNil(item.patientContextRef)
        XCTAssertNil(item.status)
        XCTAssertNil(item.statusDetail)
        XCTAssertNil(item.dependencies)
        XCTAssertNil(item.recommendedActions)
        XCTAssertNil(item.activity)
        XCTAssertNil(item.provenance)
    }

    func testMalformedCommunicationRemainsSanitizedAndVisibleButCannotNavigate() throws {
        let item = try decode(#"""
        {
          "id": "patient-communication-not-a-uuid",
          "type": "patient_communication",
          "domain": "communications",
          "title": "Patient name must not survive",
          "subtitle": "Message body must not survive",
          "unit": "5 East\nPatient Name",
          "at": "patient-specific free text",
          "patient_context_ref": "ptok_secret"
        }
        """#)

        XCTAssertTrue(item.isPatientCommunicationAttention)
        XCTAssertTrue(item.id.hasPrefix("malformed-patient-communication-"))
        XCTAssertNil(item.patientCommunicationWorkItemUUID)
        XCTAssertEqual(item.title, "Patient communication needs attention")
        XCTAssertEqual(item.subtitle, "Open the secure conversation to review the request.")
        XCTAssertNil(item.unit)
        XCTAssertNil(item.at)
        XCTAssertNil(item.patientContextRef)
        XCTAssertNil(PatientCommunicationForYouRoute(item: item, canView: true))
        XCTAssertTrue(RoleExperience.of("transport").keep(item, unitsByName: [:], myUnit: nil))
    }

    func testRouteRequiresExactTypeDomainAndLowercaseCanonicalUUID() throws {
        let exact = try decode(Self.oversharedCommunication)
        let wrongDomain = try decode(Self.oversharedCommunication.replacingOccurrences(
            of: #""domain": "communications""#,
            with: #""domain": "rtdc""#
        ))
        let uppercaseUUID = try decode(Self.oversharedCommunication.replacingOccurrences(
            of: "11111111-1111-4111-8111-111111111111",
            with: "AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA"
        ))

        XCTAssertEqual(
            PatientCommunicationForYouRoute(item: exact, canView: true)?.workItemUUID,
            "11111111-1111-4111-8111-111111111111"
        )
        XCTAssertNil(PatientCommunicationForYouRoute(item: exact, canView: false))
        XCTAssertTrue(wrongDomain.isPatientCommunicationAttention)
        XCTAssertNil(wrongDomain.patientCommunicationWorkItemUUID)
        XCTAssertNil(PatientCommunicationForYouRoute(item: wrongDomain, canView: true))
        XCTAssertNil(uppercaseUUID.patientCommunicationWorkItemUUID)
        XCTAssertNil(PatientCommunicationForYouRoute(item: uppercaseUUID, canView: true))
    }

    private func decode(_ json: String) throws -> ForYouItem {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(ForYouItem.self, from: Data(json.utf8))
    }

    fileprivate static let oversharedCommunication = #"""
    {
      "id": "patient-communication-11111111-1111-4111-8111-111111111111",
      "type": "patient_communication",
      "domain": "communications",
      "altitude": "malicious altitude free text",
      "tier": "critical",
      "visual_status": "critical",
      "status": "patient-specific status",
      "status_detail": {"value": "critical", "label": "Patient Jane Doe"},
      "title": "Jane Doe asks when she can go home",
      "subtitle": "MRN 123456: message body",
      "unit": "5 East — Medical/Surgical",
      "at": "2026-07-19T14:05:00Z",
      "patient_context_ref": "ptok_0123456789abcdef01234567",
      "dependencies": [{"label": "Patient Jane Doe", "entity_ref": "patient-123"}],
      "recommended_actions": [{"kind": "view", "label": "Open Jane Doe", "endpoint": "/patients/123"}],
      "activity": [{"free_text": "Patient message"}],
      "provenance": {"source_service": "InternalPatientTable", "metric_key": "patient.123"}
    }
    """#
}

@MainActor
final class ForYouPatientCommunicationPolicyTests: XCTestCase {
    func testEveryLegacyRoleFilterKeepsAuthorizedCommunicationAttention() throws {
        let item = try decode(ForYouPatientCommunicationModelTests.oversharedCommunication)

        for role in Role.catalog {
            XCTAssertTrue(
                RoleExperience.of(role.id).keep(item, unitsByName: [:], myUnit: "Different Unit"),
                "Role filter unexpectedly discarded communication for \(role.id)"
            )
        }
    }

    func testCapabilityGateOverridesLocalRoleWhileMalformedRowsStayVisibleWhenAuthorized() throws {
        let valid = try decode(ForYouPatientCommunicationModelTests.oversharedCommunication)
        let malformed = try decode(#"""
        {"id":"broken","type":"patient_communication","domain":"communications"}
        """#)
        let viewModel = ForYouViewModel(api: APIClient(baseURL: URL(string: "https://example.invalid")!))
        viewModel.items = [valid, malformed]
        let noLegacyQueueRole = RoleExperience.of("transport")

        XCTAssertEqual(
            viewModel.filtered(
                by: noLegacyQueueRole,
                myUnit: nil,
                canViewPatientCommunications: true
            ).count,
            2
        )
        XCTAssertTrue(
            viewModel.filtered(
                by: noLegacyQueueRole,
                myUnit: nil,
                canViewPatientCommunications: false
            ).isEmpty
        )
    }

    func testPersistentGlanceExcludesCommunicationsAndSuspendClearsMemory() throws {
        let communication = try decode(ForYouPatientCommunicationModelTests.oversharedCommunication)
        let operational = try decode(#"""
        {
          "id":"cap-85","type":"capacity","domain":"rtdc","tier":"critical",
          "title":"5 East at capacity","subtitle":"No safe admit capacity","unit":"5 East"
        }
        """#)
        let viewModel = ForYouViewModel(api: APIClient(baseURL: URL(string: "https://example.invalid")!))
        viewModel.items = [communication, operational]
        let date = Date(timeIntervalSince1970: 123)

        let snapshot = viewModel.glanceSnapshot(updatedAt: date)
        XCTAssertEqual(snapshot.pending, 1)
        XCTAssertEqual(snapshot.critical, 1)
        XCTAssertEqual(snapshot.updatedAt, date)

        viewModel.suspend()
        XCTAssertTrue(viewModel.items.isEmpty)
        XCTAssertTrue(viewModel.unitsById.isEmpty)
        XCTAssertNil(viewModel.webLink)
        XCTAssertTrue(viewModel.working.isEmpty)
    }

    func testCapabilityLossImmediatelyPurgesRestrictedIdentifiers() throws {
        let communication = try decode(ForYouPatientCommunicationModelTests.oversharedCommunication)
        let operational = try decode(#"""
        {
          "id":"cap-85","type":"capacity","domain":"rtdc","tier":"warning",
          "title":"Capacity attention","subtitle":"Operational item"
        }
        """#)
        let viewModel = ForYouViewModel(api: APIClient(baseURL: URL(string: "https://example.invalid")!))
        viewModel.items = [communication, operational]
        viewModel.working = [communication.id, operational.id]

        viewModel.purgePatientCommunications()

        XCTAssertEqual(viewModel.items.map(\.id), [operational.id])
        XCTAssertEqual(viewModel.working, [operational.id])
        XCTAssertTrue(
            viewModel.filtered(
                by: RoleExperience.of(nil),
                myUnit: nil,
                canViewPatientCommunications: false
            ).allSatisfy { !$0.isPatientCommunicationAttention }
        )
    }

    private func decode(_ json: String) throws -> ForYouItem {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(ForYouItem.self, from: Data(json.utf8))
    }
}

@MainActor
final class ForYouPatientCommunicationAPIClientTests: XCTestCase {
    override func tearDown() {
        ForYouURLProtocol.handler = nil
        super.tearDown()
    }

    func testForYouRequestIsExplicitlyNoStore() async throws {
        var captured: URLRequest?
        ForYouURLProtocol.handler = { request in
            captured = request
            return (200, Data(#"{"data":[],"meta":{"classification":"phi_minimized"},"links":{}}"#.utf8))
        }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ForYouURLProtocol.self]
        configuration.urlCache = nil
        let client = APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
        )

        _ = try await client.forYou(bearer: "staff-token")

        let request = try XCTUnwrap(captured)
        XCTAssertEqual(request.url?.path, "/api/mobile/v1/for-you")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer staff-token")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Cache-Control"), "no-store, no-cache, max-age=0")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Pragma"), "no-cache")
        XCTAssertEqual(request.cachePolicy, .reloadIgnoringLocalCacheData)
    }

    func testNoCacheFactoryHasNoCacheCookiesOrCredentialStorage() {
        let client = APIClient.noCache(baseURL: URL(string: "https://example.invalid")!)

        XCTAssertNil(client.session.configuration.urlCache)
        XCTAssertNil(client.session.configuration.httpCookieStorage)
        XCTAssertFalse(client.session.configuration.httpShouldSetCookies)
        XCTAssertNil(client.session.configuration.urlCredentialStorage)
        XCTAssertEqual(client.session.configuration.requestCachePolicy, .reloadIgnoringLocalCacheData)
    }

    func testFailedRefreshClearsPriorQueueAndNavigationLookups() async throws {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let communication = try decoder.decode(
            ForYouItem.self,
            from: Data(ForYouPatientCommunicationModelTests.oversharedCommunication.utf8)
        )
        ForYouURLProtocol.handler = { _ in
            (503, Data(#"{"error":{"message":"Unavailable"}}"#.utf8))
        }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ForYouURLProtocol.self]
        configuration.urlCache = nil
        let client = APIClient(
            baseURL: URL(string: "https://example.invalid")!,
            session: URLSession(configuration: configuration),
            tokenCoordinator: nil
        )
        let viewModel = ForYouViewModel(api: client)
        viewModel.items = [communication]
        viewModel.unitsById = [85: CensusUnit(
            unitId: 85,
            name: "5 East",
            type: "med_surg",
            staffedBedCount: 20,
            occupied: 18,
            available: 2,
            blocked: 0,
            canAdmit: 2,
            bedNeed: 0,
            status: "warning"
        )]
        viewModel.webLink = "https://example.invalid/unit/85"

        await viewModel.load(bearer: "staff-token")

        XCTAssertTrue(viewModel.items.isEmpty)
        XCTAssertTrue(viewModel.unitsById.isEmpty)
        XCTAssertNil(viewModel.webLink)
        XCTAssertEqual(viewModel.errorMessage, "Unavailable")
    }
}

private final class ForYouURLProtocol: URLProtocol {
    static var handler: ((URLRequest) throws -> (Int, Data))?

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        do {
            let handler = try XCTUnwrap(Self.handler)
            let (status, data) = try handler(request)
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
