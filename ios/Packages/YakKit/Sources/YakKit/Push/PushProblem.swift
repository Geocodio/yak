import Foundation

/// Every way a push request can fail, each one a state the screens show.
public enum PushProblem: Error, Equatable, Codable, Sendable {
    case pushUnavailable
    case environmentMismatch(expected: String, given: String)
    case rejected(reason: String, message: String)
    case noPushToken
    case threadClosed
    case notFound
    case invalid(message: String)
    case rateLimited(retryAfterSeconds: Int?)
    case unavailable
    case offline
    case needsPairing
    case appTooOld
    case serverTooOld
    case other(message: String)

    public init(_ failure: APIFailure) {
        switch failure {
        case .server(let error): self = Self.problem(for: error)
        case .network(let code): self = code == .timedOut ? .unavailable : .offline
        case .unauthorized: self = .needsPairing
        case .appTooOld: self = .appTooOld
        case .serverTooOld: self = .serverTooOld
        case .notYak: self = .other(message: "")
        case .encodingFailed: self = .other(message: "")
        }
    }

    private static func problem(for error: APIError) -> PushProblem {
        switch (error.status, error.code) {
        case (409, "push_unavailable"): return .pushUnavailable
        case (409, "push_environment_mismatch"):
            return .environmentMismatch(
                expected: error.details["expected"]?.stringValue ?? "",
                given: error.details["given"]?.stringValue ?? "")
        case (409, "push_rejected"):
            return .rejected(reason: error.details["reason"]?.stringValue ?? "", message: error.message)
        case (409, "no_push_token"): return .noPushToken
        case (409, "thread_closed"): return .threadClosed
        case (404, _): return .notFound
        case (422, _): return .invalid(message: error.message)
        case (429, _): return .rateLimited(retryAfterSeconds: error.retryAfterSeconds)
        case (500..., _): return .unavailable
        default: return .other(message: error.message)
        }
    }
}
