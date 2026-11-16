import Foundation

/// Why the sandbox cannot answer: `waking`, `asleep`, `destroyed`, `closed_destroyed`, `on_device`.
/// Unknown values are kept.
public struct SandboxUnavailableState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let waking = SandboxUnavailableState(rawValue: "waking")
    public static let asleep = SandboxUnavailableState(rawValue: "asleep")
    public static let destroyed = SandboxUnavailableState(rawValue: "destroyed")
    public static let closedDestroyed = SandboxUnavailableState(rawValue: "closed_destroyed")
    public static let onDevice = SandboxUnavailableState(rawValue: "on_device")
}

private func flag(_ value: JSONValue?) -> Bool {
    if case .bool(let flag)? = value { return flag }
    return false
}

/// The `sandbox_unavailable` refusal of the file endpoints.
public struct SandboxUnavailable: Equatable, Sendable {
    public let state: SandboxUnavailableState
    public let message: String
    public let canRestore: Bool
    public let waitingForSlot: Bool
    public let restoring: Bool

    /// Nil unless the error's code is `sandbox_unavailable`. Missing booleans read as false. A missing
    /// `details.state` reads as the state "unknown", which callers show as the generic unavailable screen.
    public init?(_ error: APIError) {
        guard error.code == "sandbox_unavailable" else { return nil }
        state = SandboxUnavailableState(rawValue: error.details["state"]?.stringValue ?? "unknown")
        message = error.message
        canRestore = flag(error.details["canRestore"])
        waitingForSlot = flag(error.details["waitingForSlot"])
        restoring = flag(error.details["restoring"])
    }
}

/// The `file_conflict` refusal of a save.
public struct FileConflict: Equatable, Sendable {
    public let message: String
    /// Nil when the file was deleted meanwhile.
    public let file: FileBody?

    /// Nil unless the error's code is `file_conflict` and `details.file` is either JSON null (the file was
    /// deleted meanwhile) or a file object that decodes. A missing key or a malformed file object is not a
    /// deletion: the caller handles it as an unknown refusal, so a save never overwrites on a guess.
    public init?(_ error: APIError) {
        guard error.code == "file_conflict", let value = error.details["file"] else { return nil }
        if value == .null {
            file = nil
        } else {
            guard let data = try? JSONEncoder().encode(value),
                let body = try? JSONDecoder().decode(FileBody.self, from: data)
            else { return nil }
            file = body
        }
        message = error.message
    }
}
