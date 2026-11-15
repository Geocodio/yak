public enum TaskSubtitle: Equatable, Sendable {
    case waitsForCoOwner, waitsForAnswer
    case running(source: String)
    case checkingCI(pr: Int?)
    case retrying
    /// A finished task with a pull request.
    case pullRequest(number: Int?, state: String)
    case done(source: String)
    case failed, expired, cancelled, pending
    case other(status: String)
}

public enum TaskRowText {
    /// The subtitle of a row. Status words come from `status`, never guessed.
    public static func subtitle(_ row: TaskRow) -> TaskSubtitle {
        switch row.status {
        case .awaitingCoOwner: .waitsForCoOwner
        case .awaitingClarification: .waitsForAnswer
        case .running: .running(source: row.sourceLabel)
        case .awaitingCI: .checkingCI(pr: row.pr?.number)
        case .retrying: .retrying
        case .success:
            if let pr = row.pr {
                .pullRequest(number: pr.number, state: pr.state)
            } else {
                .done(source: row.sourceLabel)
            }
        case .failed: .failed
        case .expired: .expired
        case .cancelled: .cancelled
        case .pending: .pending
        default: .other(status: row.status.rawValue)
        }
    }

    /// True when the row waits for the person: a co-owner decision or an answer.
    public static func needsYou(_ row: TaskRow) -> Bool {
        row.status == .awaitingCoOwner || row.status == .awaitingClarification
    }
}
