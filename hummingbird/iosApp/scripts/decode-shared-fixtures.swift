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

        print("Decoded 4 shared Hummingbird DTO fixtures.")
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
