import Foundation

enum FixtureDecodeError: Error, CustomStringConvertible {
    case repoRootNotFound
    case assertionFailed(String)

    var description: String {
        switch self {
        case .repoRootNotFound:
            return "Unable to locate repository root from the current directory."
        case .assertionFailed(let message):
            return message
        }
    }
}

@main
enum DecodeSharedFixtures {
    static func main() throws {
        let root = try repoRoot()
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase

        let home = try decode("mobile-altitude-home.json", as: Envelope<MobileAltitudeHome>.self, root: root, decoder: decoder)
        try require(home.data.altitude == "A0", "Altitude home fixture did not decode A0.")
        try require(home.data.persona?.roleId == "bed_manager", "Altitude home fixture decoded the wrong persona.")
        try require(home.data.forYouHead?.first?.capacity == .critical, "Altitude home For You visual status did not decode as critical.")

        let forYou = try decode("mobile-for-you.json", as: Envelope<[ForYouItem]>.self, root: root, decoder: decoder)
        try require(forYou.data.count == 2, "For You fixture item count drifted.")
        try require(forYou.data.first?.capacity == .warning, "For You fixture visual_status was not preferred over legacy tier.")

        let activity = try decode("mobile-activity-feed.json", as: Envelope<[ActivityEvent]>.self, root: root, decoder: decoder)
        try require(activity.data.first?.eventType == "transport.progressed", "Activity fixture event type drifted.")
        try require(activity.data.first?.severity == .warning, "Activity fixture severity did not decode from status.severity.")

        let patient = try decode("mobile-patient-operational-context.json", as: Envelope<PatientOperationalContext>.self, root: root, decoder: decoder)
        try require(patient.data.altitude == "A2P", "Patient context fixture did not decode A2P.")
        try require(patient.data.patient.patientContextRef == "ptok_demo_flow_42", "Patient context ref drifted.")
        try require(patient.data.statusSpine.count == 2, "Patient status spine fixture count drifted.")
        try require(patient.data.dependencies.count == 2, "Patient dependency fixture count drifted.")

        let flowWindow = try decode("mobile-flow-window.json", as: Envelope<FlowWindowData>.self, root: root, decoder: decoder)
        try require(flowWindow.data.lens.roleId == "bed_manager", "Flow window fixture decoded the wrong lens role.")
        try require(flowWindow.data.lens.patientDots == "full", "Flow window lens patient_dots drifted.")
        try require(flowWindow.data.scope.type == "house", "Flow window fixture scope drifted.")
        try require(flowWindow.data.spaces?.floors.count == 11, "Flow window floor rollup count drifted.")
        try require(flowWindow.data.events.count == 5, "Flow window event count drifted.")
        try require(flowWindow.data.events.first?.kind == "admit", "Flow window first event kind drifted.")
        try require(flowWindow.data.events.contains { $0.kind == "transport_status" && $0.fromSpace == "ED" },
                    "Flow window transport_status event route drifted.")
        try require(flowWindow.data.events.contains { $0.kind == "evs_status" },
                    "Flow window evs_status event missing.")
        try require(flowWindow.data.projections.first?.kind == "scheduled_or_case", "Flow window first projection kind drifted.")
        try require(flowWindow.data.projections.contains { $0.kind == "scheduled_or_case" && $0.room != nil },
                    "Flow window scheduled_or_case lost its room.")
        try require(flowWindow.data.projections.contains { $0.derived == true && $0.kind == "transport_due" },
                    "Flow window derived transport ghost missing.")
        try require(flowWindow.data.projections.allSatisfy { ["definite", "probable", "possible"].contains($0.confidence) },
                    "Flow window projection confidence vocabulary drifted.")
        try require(flowWindow.data.bedStatuses.isEmpty, "Flow window house scope must not carry bed_statuses.")
        try require(FlowTime.parse(flowWindow.data.window.now) != nil, "Flow window `now` timestamp failed ISO-8601 parse.")

        let evsWindow = try decode("mobile-flow-window-evs.json", as: Envelope<FlowWindowData>.self, root: root, decoder: decoder)
        try require(evsWindow.data.lens.roleId == "evs", "EVS flow window fixture decoded the wrong lens role.")
        try require(evsWindow.data.scope.type == "floor", "EVS flow window fixture scope drifted.")
        try require(!evsWindow.data.bedStatuses.isEmpty, "EVS flow window bed_statuses missing or empty.")
        try require(evsWindow.data.bedStatuses.allSatisfy { ["available", "occupied", "blocked", "dirty"].contains($0.status) },
                    "EVS flow window bed status vocabulary drifted.")

        let flowFloors = try decode("mobile-flow-floors.json", as: Envelope<FlowFloorsDocument>.self, root: root, decoder: decoder)
        try require(flowFloors.data.version == "v1-a8f91dc9a9e4", "Flow floors plates version drifted.")
        try require(flowFloors.data.floors.first?.spaces.count == 4, "Flow floors plate count drifted.")
        try require(flowFloors.data.floors.first?.bounds.count == 4, "Flow floors bounds shape drifted.")
        try require(flowFloors.data.floors.contains { floor in floor.spaces.contains { $0.bedId == 693 && $0.rect.count == 4 } },
                    "Flow floors bed plate bridge (bed_id + rect) drifted.")

        let transport = try decode("mobile-transport-queue.json", as: Envelope<TransportQueue>.self, root: root, decoder: decoder)
        try require(transport.data.jobs.first?.claimedByMe == true, "Transport assignment ownership did not decode.")
        try require(transport.data.jobs.first?.allowedTransitions == ["dispatched", "escalated", "failed"],
                    "Transport server-authorized transitions drifted.")
        try require(transport.data.jobs.first?.lifecycleVersion == 3, "Transport lifecycle version drifted.")
        try require(transport.meta?.hasMore == true && transport.meta?.nextCursor?.isEmpty == false,
                    "Transport cursor metadata did not decode.")

        print("Decoded 8 shared Hummingbird DTO fixtures.")
    }

    private static func decode<T: Decodable>(
        _ filename: String,
        as type: T.Type,
        root: URL,
        decoder: JSONDecoder
    ) throws -> T {
        let url = root
            .appendingPathComponent("docs/hummingbird/api-contract/fixtures")
            .appendingPathComponent(filename)
        let data = try Data(contentsOf: url)

        return try decoder.decode(T.self, from: data)
    }

    private static func repoRoot() throws -> URL {
        let startPath = CommandLine.arguments.dropFirst().first ?? FileManager.default.currentDirectoryPath
        var cursor = URL(fileURLWithPath: startPath, isDirectory: true).standardizedFileURL
        let fileManager = FileManager.default

        while cursor.path != "/" {
            let marker = cursor.appendingPathComponent("docs/hummingbird/api-contract/fixtures").path
            if fileManager.fileExists(atPath: marker) {
                return cursor
            }
            cursor.deleteLastPathComponent()
        }

        throw FixtureDecodeError.repoRootNotFound
    }

    private static func require(_ condition: Bool, _ message: String) throws {
        if !condition {
            throw FixtureDecodeError.assertionFailed(message)
        }
    }
}
