import Foundation

public enum OutboxDecision: Equatable, Sendable {
    case retry(after: Duration)
    case refuse(OutboxRefusal)
    case pause(OutboxPause)
    /// The attempt was cancelled: no failure, but it counts as an attempt (the request may have arrived), so
    /// repeated cancellations move the item up the backoff table.
    case stayQueued
}

/// What to do with a failed attempt.
public enum OutboxRetryPolicy {
    /// The wait after attempt 1 to 5; every later attempt waits `laterDelay`.
    public static let delays: [Duration] = [.seconds(1), .seconds(2), .seconds(4), .seconds(8), .seconds(15)]
    public static let laterDelay: Duration = .seconds(30)
    public static let slowAfter: Duration = .seconds(5)

    /// `attempt` counts from 1.
    public static func decision(for failure: APIFailure, attempt: Int) -> OutboxDecision {
        let scheduled = attempt >= 1 && attempt <= delays.count ? delays[attempt - 1] : laterDelay
        switch failure {
        case .network(let code):
            return code == .cancelled ? .stayQueued : .retry(after: scheduled)
        case .notYak:
            return .retry(after: scheduled)
        case .unauthorized(let code):
            return .pause(.unauthorized(code: code))
        case .serverTooOld:
            return .pause(.serverTooOld)
        case .appTooOld:
            return .refuse(OutboxRefusal(code: "protocol_too_old", message: ""))
        case .encodingFailed:
            return .refuse(OutboxRefusal(code: "encoding_failed", message: ""))
        case .server(let error):
            if error.status == 429 {
                guard let seconds = error.retryAfterSeconds else { return .retry(after: scheduled) }
                return .retry(after: .seconds(min(max(seconds, 1), 300)))
            }
            if error.status >= 500 { return .retry(after: scheduled) }
            return .refuse(OutboxRefusal(code: error.code, message: error.message))
        }
    }
}
