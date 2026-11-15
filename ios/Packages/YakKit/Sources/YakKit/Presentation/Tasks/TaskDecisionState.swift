import Foundation

/// What the co-owner panel of a task offers the person looking at it. The server's flags decide; the app only reads them.
public enum TaskDecisionState: Equatable, Sendable {
    /// No co-owner request, or the viewer cannot drive.
    case none
    /// Approve, Request changes, Reject.
    case decide
    /// The viewer is the requester: Withdraw only.
    case waitingForOthers(asked: [String])
    /// "Link your GitHub account to decide".
    case linkGitHub(connectURL: String)
    /// "You need write access to <repo> to decide".
    case noWriteAccess
    /// "Yak could not check your GitHub access." with Retry.
    case accessCheckFailed
    /// approved, changes_requested, rejected, withdrawn.
    case decided(status: String, by: String?)

    public static func make(_ task: AppTask) -> TaskDecisionState {
        guard task.canDrive, let panel = task.coOwner else { return .none }
        guard panel.status == "pending" else { return .decided(status: panel.status, by: panel.decidedBy) }
        if panel.viewer.isRequester { return .waitingForOthers(asked: panel.asked) }
        guard panel.viewer.isLinked else { return .linkGitHub(connectURL: panel.connectUrl) }
        switch panel.viewer.access {
        case "write": return .decide
        case "read": return .noWriteAccess
        default: return .accessCheckFailed
        }
    }
}

/// The early "ask a co-owner" notice of a task that has no co-owner request yet.
public enum EarlyWarningRow: Equatable, Sendable {
    case none
    /// Tag a co-owner or "Not needed".
    case pick(fallbackName: String?)
    case asked(name: String)

    public static func make(_ task: AppTask) -> EarlyWarningRow {
        guard task.canDrive, let notice = task.earlyWarning else { return .none }
        if notice.state == "tagged", let name = notice.taggedName { return .asked(name: name) }
        if notice.canPick { return .pick(fallbackName: notice.fallbackName) }
        return .none
    }
}
