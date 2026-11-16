/// Why a pairing did not finish, each with its own screen state.
public enum PairingProblem: Error, Equatable, Sendable {
    case invalidAddress, notYak, unreachable, serverTooOld, appTooOld, codeInvalid, codeExpired, denied
    /// Redeeming the code failed without an answer, so the instance may have accepted it.
    case codeMayBeUsed
    case cancelled
    /// Diagnostic only: the payload may be a raw server message or error code and must not be shown
    /// as is. Screens show a plain-words message with a retry.
    case other(String)

    init(_ failure: APIFailure) {
        switch failure {
        case .notYak: self = .notYak
        case .network(let code): self = code == .cancelled ? .cancelled : .unreachable
        case .appTooOld: self = .appTooOld
        case .serverTooOld: self = .serverTooOld
        case .server(let error):
            switch error.code {
            case "pairing_code_invalid": self = .codeInvalid
            case "pairing_code_expired", "expired_token": self = .codeExpired
            case "access_denied": self = .denied
            default: self = .other(error.message.isEmpty ? error.code : error.message)
            }
        case .unauthorized(let code): self = .other(code)
        case .encodingFailed: self = .other("encoding_failed")
        }
    }
}
