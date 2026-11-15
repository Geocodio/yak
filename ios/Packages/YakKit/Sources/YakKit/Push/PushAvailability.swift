import Foundation

/// The app identifier and push environment this build was signed for.
public struct PushBuild: Equatable, Sendable {
    public let bundleID: String
    public let environment: PushEnvironment

    public init(bundleID: String, environment: PushEnvironment) {
        self.bundleID = bundleID
        self.environment = environment
    }
}

/// Whether an instance can push to this build, and if not, why.
public enum PushAvailability: Codable, Equatable, Sendable {
    case available
    /// The instance has no push key (`push.apns` is false).
    case noKey
    /// The instance's key is for another app identifier.
    case otherBuild
    /// The instance pushes through another environment; nil when it names one this app does not know.
    case otherEnvironment(instance: PushEnvironment?)

    public static func make(_ push: Me.PushState, build: PushBuild?) -> PushAvailability {
        guard push.apns else { return .noKey }
        guard let build else { return .available }
        guard push.bundleId == build.bundleID else { return .otherBuild }
        let name = push.environment ?? PushEnvironment.production.rawValue
        guard let environment = PushEnvironment(rawValue: name) else { return .otherEnvironment(instance: nil) }
        return environment == build.environment ? .available : .otherEnvironment(instance: environment)
    }
}
