import Foundation

public enum TaskAction: Equatable, Sendable {
    case retry, cancel, rerunReview, retryRender
    case reroute(repo: String)
    case approve
    case requestChanges(feedback: String)
    case reject, withdraw
    case tag(userID: Int)
    case decline
}

/// How a person's action on a task ended, each case a designed state for the screen.
public enum TaskActionOutcome: Equatable, Sendable {
    /// The status word the server answered.
    case done(String)
    /// The server said no, in words the person can read. `connectURL` is set when GitHub must be linked first.
    case refused(code: String, message: String, fieldErrors: [String: [String]], connectURL: String?)
    /// A network failure, a timeout or a 503 `unavailable`: refresh the task, never resend.
    case failed(ThreadProblem)

    public init(_ failure: APIFailure) {
        guard case .server(let error) = failure else {
            self = .failed(ThreadProblem(failure))
            return
        }
        let isRefusal = (400..<500).contains(error.status) || (error.status >= 500 && error.code != "unavailable")
        guard isRefusal, error.code != "unknown" else {
            self = .failed(ThreadProblem(failure))
            return
        }
        var fieldErrors: [String: [String]] = [:]
        if case .object(let fields)? = error.details["errors"] {
            for (field, value) in fields {
                if case .array(let messages) = value { fieldErrors[field] = messages.compactMap(\.stringValue) }
            }
        }
        self = .refused(
            code: error.code, message: error.message, fieldErrors: fieldErrors,
            connectURL: error.details["connectUrl"]?.stringValue)
    }
}
