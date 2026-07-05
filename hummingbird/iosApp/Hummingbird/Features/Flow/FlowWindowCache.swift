import Foundation

/// On-disk cache of the last FULL (non-delta) Flow Window per (user, persona, scope), so a
/// failed load (offline / server error) can still present the last-known window — and its
/// past half stays fully scrubbable offline. Records store the raw envelope bytes verbatim
/// (window + floors), keyed by the authenticated user id so one user's cache is never served
/// to another. FLOW-WINDOW-PLAN §7.2 (offline), Phase 5.
///
/// Storage: JSON files under Application Support/FlowCache, written atomically, capped LRU at
/// ~20 records (a signed-in worker touches a handful of scopes; older ones age out).
enum FlowWindowCache {
    /// One cached window: the raw `/flow/window` and `/flow/floors` envelope bytes plus the
    /// wall-clock capture time (drives the "Showing data from HH:mm" staleness caption).
    struct Record: Codable {
        let schemaVersion: Int
        let userId: Int
        let persona: String
        let scope: String
        let capturedAt: Date
        let window: Data
        let floors: Data?
    }

    private static let schemaVersion = 1
    private static let maxEntries = 20

    private static var directory: URL? {
        let fm = FileManager.default
        guard let base = fm.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else { return nil }
        let dir = base.appendingPathComponent("FlowCache", isDirectory: true)
        if !fm.fileExists(atPath: dir.path) {
            try? fm.createDirectory(at: dir, withIntermediateDirectories: true)
        }
        return dir
    }

    /// Stable, filesystem-safe filename for a cache key. Percent-encoding keeps persona/scope
    /// separators (`|`, `:`) out of the path without a hash dependency.
    private static func fileURL(userId: Int, persona: String, scope: String) -> URL? {
        let raw = "\(userId)|\(persona)|\(scope)"
        let safe = raw.addingPercentEncoding(withAllowedCharacters: .alphanumerics) ?? "\(userId)"
        return directory?.appendingPathComponent("\(safe).json")
    }

    static func save(userId: Int, persona: String?, scope: String, window: Data, floors: Data?) {
        guard let url = fileURL(userId: userId, persona: persona ?? "", scope: scope) else { return }
        let record = Record(schemaVersion: schemaVersion, userId: userId, persona: persona ?? "",
                            scope: scope, capturedAt: Date(), window: window, floors: floors)
        guard let encoded = try? JSONEncoder().encode(record) else { return }
        try? encoded.write(to: url, options: .atomic)
        pruneLRU()
    }

    static func load(userId: Int, persona: String?, scope: String) -> Record? {
        guard let url = fileURL(userId: userId, persona: persona ?? "", scope: scope),
              let data = try? Data(contentsOf: url),
              let record = try? JSONDecoder().decode(Record.self, from: data),
              record.schemaVersion == schemaVersion,
              record.userId == userId else { return nil }
        // Touch modification time so this key counts as recently used for LRU pruning.
        try? FileManager.default.setAttributes([.modificationDate: Date()], ofItemAtPath: url.path)
        return record
    }

    /// Clear the whole cache — hooked to sign-out (a signed-out device keeps no operational
    /// state) and the belt-and-braces guard against ever serving another user's window.
    static func clearAll() {
        guard let directory,
              let contents = try? FileManager.default.contentsOfDirectory(at: directory,
                includingPropertiesForKeys: nil) else { return }
        for url in contents { try? FileManager.default.removeItem(at: url) }
    }

    /// Keep at most `maxEntries` records, dropping the least-recently-modified first.
    private static func pruneLRU() {
        guard let directory,
              let contents = try? FileManager.default.contentsOfDirectory(at: directory,
                includingPropertiesForKeys: [.contentModificationDateKey]),
              contents.count > maxEntries else { return }
        let sorted = contents.sorted { lhs, rhs in
            let l = (try? lhs.resourceValues(forKeys: [.contentModificationDateKey]))?.contentModificationDate ?? .distantPast
            let r = (try? rhs.resourceValues(forKeys: [.contentModificationDateKey]))?.contentModificationDate ?? .distantPast
            return l < r
        }
        for url in sorted.prefix(contents.count - maxEntries) {
            try? FileManager.default.removeItem(at: url)
        }
    }
}
