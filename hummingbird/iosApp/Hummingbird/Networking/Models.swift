import Foundation

// DTOs matching the Hummingbird BFF (hummingbird-bff.v1.yaml). The decoder uses
// .convertFromSnakeCase, so snake_case JSON maps to camelCase here.

/// POST /api/auth/token — either a token pair, or a must-change-password challenge.
struct TokenResponse: Decodable {
    let tokenType: String?
    let accessToken: String?
    let refreshToken: String?
    let expiresIn: Int?
    let abilities: [String]?
    let passwordChangeRequired: Bool?
    let changeToken: String?
}

/// The uniform BFF envelope: { data, meta, links }.
struct Envelope<T: Decodable>: Decodable {
    let data: T
    let meta: Meta?
    let links: [String: String]?
}

struct Meta: Decodable {
    let asOf: String?
    let stale: Bool?
    let version: Int?
}

/// GET /api/mobile/v1/me
struct MeData: Decodable {
    let id: Int
    let name: String
    let username: String
    let email: String?
    let roles: [String]
    let isAdmin: Bool
    let workflowPreference: String?
    let mustChangePassword: Bool
    let units: [UnitAssignment]
}

struct UnitAssignment: Decodable, Identifiable {
    let unitId: Int
    let name: String
    let role: String?
    let isPrimary: Bool
    var id: Int { unitId }
}

/// GET /api/mobile/v1/rtdc/census — one entry per unit.
struct CensusUnit: Decodable, Identifiable {
    let unitId: Int
    let name: String
    let type: String
    let staffedBedCount: Int
    let occupied: Int
    let available: Int
    let blocked: Int
    let safeCapacity: Int
    let bedNeed: Int
    let status: String

    var id: Int { unitId }
    var capacity: CapacityStatus { CapacityStatus(apiValue: status) }
}

/// GET /api/mobile/v1/for-you — one prioritized, PHI-minimized action item.
struct ForYouItem: Decodable, Identifiable {
    let id: String
    let type: String
    let tier: String
    let title: String
    let subtitle: String
    let unit: String?
    let at: String?

    var capacity: CapacityStatus { CapacityStatus(apiValue: tier) }
}

/// An error surfaced from the API (either a Laravel `{message}` or a BFF `{error:{message}}`).
struct APIError: Error {
    let message: String
    let statusCode: Int?
}
