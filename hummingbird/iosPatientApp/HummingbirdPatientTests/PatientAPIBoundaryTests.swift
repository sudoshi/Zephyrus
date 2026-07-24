import XCTest
import UIKit
@testable import HummingbirdPatient

final class PatientAPIBoundaryTests: XCTestCase {
    func testEveryEndpointStaysInsideThePatientV1Boundary() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        let forbidden = ["/api/" + "mobile", "/api/" + "auth"]
        let referenceEncounterUUID = "019f0000-0000-7000-8000-000000000099"
        let endpoints = PatientAPIEndpoint.inventory(referenceEncounterUUID: referenceEncounterUUID)

        XCTAssertEqual(endpoints.count, 23)
        XCTAssertEqual(Set(endpoints.map(\.path)), [
            "/api/patient/v1/auth/enroll/challenge/verify",
            "/api/patient/v1/auth/token",
            "/api/patient/v1/auth/token/refresh",
            "/api/patient/v1/auth/token/revoke",
            "/api/patient/v1/me",
            "/api/patient/v1/me/preferences",
            "/api/patient/v1/me/sessions",
            "/api/patient/v1/me/sessions/\(referenceEncounterUUID)",
            "/api/patient/v1/encounters",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/today",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/pathway",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/pathway/events",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/discharge-readiness",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/rounds/summary",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/care-team",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/message-topics",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/threads",
            "/api/patient/v1/encounters/\(referenceEncounterUUID)/education/\(referenceEncounterUUID)/clarifications",
            "/api/patient/v1/threads/\(referenceEncounterUUID)",
            "/api/patient/v1/threads/\(referenceEncounterUUID)/messages",
            "/api/patient/v1/threads/\(referenceEncounterUUID)/messages/\(referenceEncounterUUID)/amend",
            "/api/patient/v1/threads/\(referenceEncounterUUID)/close",
        ])
        XCTAssertEqual(
            Set(endpoints.map { "\($0.method) \($0.path)" }).count,
            23,
            "Every consumed operation must have a unique HTTP method and path pair."
        )
        for endpoint in endpoints {
            let url = try endpoint.url(relativeTo: baseURL)
            XCTAssertTrue(url.path.hasPrefix(PatientAPIBoundary.path + "/"), endpoint.path)
            XCTAssertFalse(forbidden.contains(where: url.path.hasPrefix), endpoint.path)
            XCTAssertEqual(url.host, baseURL.host)
            XCTAssertNil(url.query)
            XCTAssertNil(url.fragment)
        }
        XCTAssertEqual(endpoints.filter { $0.method == "POST" }.count, 9)
        XCTAssertEqual(endpoints.filter { $0.method == "PUT" }.count, 1)
        XCTAssertEqual(endpoints.filter { $0.method == "GET" }.count, 12)
        XCTAssertEqual(endpoints.filter { $0.method == "DELETE" }.count, 1)
    }

    func testMessageEndpointRejectsAnInvalidThreadIdentifier() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        XCTAssertThrowsError(
            try PatientAPIEndpoint.sendMessage(threadUUID: "../staff-thread")
                .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
    }

    func testMessageAmendmentEndpointRejectsInvalidThreadOrMessageIdentifiers() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        XCTAssertThrowsError(
            try PatientAPIEndpoint.amendMessage(
                threadUUID: "../another-boundary",
                messageUUID: "019f0000-0000-7000-8000-000000000099"
            )
            .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
        XCTAssertThrowsError(
            try PatientAPIEndpoint.amendMessage(
                threadUUID: "019f0000-0000-7000-8000-000000000099",
                messageUUID: "../another-boundary"
            )
            .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
    }

    func testProjectionEndpointRejectsAnInvalidEncounterIdentifier() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        XCTAssertThrowsError(
            try PatientAPIEndpoint.today(encounterUUID: "../another-boundary")
                .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
    }

    func testEducationClarificationEndpointRejectsInvalidOpaqueHandles() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        XCTAssertThrowsError(
            try PatientAPIEndpoint.educationClarification(
                encounterUUID: "019f0000-0000-7000-8000-000000000099",
                educationItemUUID: "../unreleased-education"
            )
            .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
    }

    func testSessionRevocationRejectsAnInvalidSessionIdentifier() throws {
        let baseURL = try XCTUnwrap(URL(string: "https://patient.example.test"))
        XCTAssertThrowsError(
            try PatientAPIEndpoint.revokeSession(sessionUUID: "../another-patient")
                .url(relativeTo: baseURL)
        ) { error in
            XCTAssertEqual(error as? PatientAPIError, .invalidBoundary)
        }
    }

    func testUnsafeBaseURLsAreRejected() {
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL(nil))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL(""))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("http://patient.example.test"))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("https://user:secret@patient.example.test"))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("https://patient.example.test/another-root"))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("https://patient.example.test:0"))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("https://patient.example.test:65536"))
        XCTAssertNotNil(PatientAPIBoundary.validatedBaseURL("https://patient.example.test"))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL("http://localhost:8001"))
        XCTAssertNotNil(PatientAPIBoundary.validatedBaseURL(
            "https://zephyrus.acumenus.net",
            environment: .production
        ))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL(
            "https://patient.example.test",
            environment: .production
        ))
        XCTAssertNil(PatientAPIBoundary.validatedBaseURL(
            "https://zephyrus.acumenus.net:8443",
            environment: .production
        ))
    }

    func testGovernedPatientSessionRefusesRedirects() throws {
        let delegate = PatientNoRedirectDelegate()
        let session = PatientURLSessionFactory.ephemeral()
        let original = try XCTUnwrap(URL(
            string: "https://zephyrus.acumenus.net/api/patient/v1/me"
        ))
        let redirected = try XCTUnwrap(URL(
            string: "https://redirected.example.test/credential-target"
        ))
        let task = session.dataTask(with: original)
        let response = try XCTUnwrap(HTTPURLResponse(
            url: original,
            statusCode: 302,
            httpVersion: "HTTP/1.1",
            headerFields: ["Location": redirected.absoluteString]
        ))
        var proposedRequest: URLRequest? = URLRequest(url: redirected)

        delegate.urlSession(
            session,
            task: task,
            willPerformHTTPRedirection: response,
            newRequest: URLRequest(url: redirected)
        ) { proposedRequest = $0 }

        XCTAssertNil(proposedRequest)
        XCTAssertTrue(session.delegate is PatientNoRedirectDelegate)
    }

    func testPatientStorageUsesDedicatedNamespaces() {
        XCTAssertEqual(PatientStorageNamespace.keychainService, "net.acumenus.hummingbird.patient.credentials")
        XCTAssertEqual(PatientStorageNamespace.preferencesSuite, "net.acumenus.hummingbird.patient.preferences")
        XCTAssertTrue(PatientStorageNamespace.accessTokenAccount.hasPrefix("patient-"))
        XCTAssertTrue(PatientStorageNamespace.refreshTokenAccount.hasPrefix("patient-"))
    }

    func testAPIIsFailClosedUnlessBothFlagAndValidBaseURLArePresent() {
        let defaultConfiguration = PatientAppConfiguration.from(info: [:], environment: [:])
        XCTAssertFalse(defaultConfiguration.patientAPIEnabled)
        XCTAssertNil(defaultConfiguration.patientAPIBaseURL)

        let flagOnly = PatientAppConfiguration.from(
            info: [PatientAppConfiguration.apiEnabledInfoKey: true],
            environment: [:]
        )
        XCTAssertFalse(flagOnly.patientAPIEnabled)

        let configured = PatientAppConfiguration.from(
            info: [
                PatientAppConfiguration.apiEnabledInfoKey: true,
                PatientAppConfiguration.apiBaseURLInfoKey: "https://patient.example.test",
            ],
            environment: [:]
        )
        XCTAssertTrue(configured.patientAPIEnabled)
    }

    func testEveryVisualStateUsesAnIncludedHummingbirdPhotoAsset() {
        let names = Set(PatientPhotoScene.allCases.map(\.assetName))
        XCTAssertEqual(names, [
            "PatientAiryFlight",
            "PatientCalmGreen",
            "PatientCareConnection",
            "PatientWarmMotion",
        ])
        for name in names {
            XCTAssertNotNil(UIImage(named: name), "Missing patient background asset \(name)")
        }
    }
}
