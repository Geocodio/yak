import SwiftUI
import YakKit

/// One row of the Tasks list: the description, what the task waits for, its age and its follow-ups. A task that
/// waits for the person carries a light tint on the whole row.
struct TaskRowView: View {
    let row: TaskRow
    let open: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let needsYou = TaskRowText.needsYou(row)
        Button(action: open) {
            VStack(alignment: .leading, spacing: 4) {
                Text(row.description).font(.body).foregroundStyle(.primary).multilineTextAlignment(.leading)
                    .lineLimit(dynamicTypeSize.isAccessibilitySize ? nil : 3)
                Text(Self.subtitle(TaskRowText.subtitle(row))).font(.footnote.weight(.medium))
                    .foregroundStyle(needsYou ? Color.yakAction : Color.yakMuted).multilineTextAlignment(.leading)
                Text(verbatim: "\(row.repo) · \(row.createdAgo)").multilineTextAlignment(.leading)
                    .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                if !row.followUps.isEmpty {
                    Text("tasks.followUps \(row.followUps.count)").font(.footnote.weight(.medium))
                        .foregroundStyle(Color.yakMuted)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20).padding(.vertical, 10)
            .frame(minHeight: 44)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .background(needsYou ? Color.yakAction.opacity(0.10) : Color.clear)
        .accessibilityIdentifier("tasks.row.\(row.id)")
    }

    static func subtitle(_ subtitle: TaskSubtitle) -> String {
        switch subtitle {
        case .waitsForCoOwner: String(localized: "tasks.status.waitsForCoOwner")
        case .waitsForAnswer: String(localized: "tasks.status.waitsForAnswer")
        case .running(let source): String(localized: "tasks.status.running \(source)")
        case .checkingCI(let number):
            number.map { String(localized: "tasks.status.checkingNumber \($0)") }
                ?? String(localized: "tasks.status.checking")
        case .retrying: String(localized: "tasks.status.retrying")
        case .pullRequest(let number, let state):
            number.map { String(localized: "tasks.status.pullRequestNumber \($0) \(stateWord(state))") }
                ?? String(localized: "tasks.status.pullRequest \(stateWord(state))")
        case .done(let source): String(localized: "tasks.status.done \(source)")
        case .failed: String(localized: "tasks.status.failed")
        case .expired: String(localized: "tasks.status.expired")
        case .cancelled: String(localized: "tasks.status.cancelled")
        case .pending: String(localized: "tasks.status.pending")
        case .other(let status): status.replacingOccurrences(of: "_", with: " ")
        }
    }

    private static func stateWord(_ state: String) -> String {
        switch state {
        case "open": String(localized: "tasks.pullRequest.open")
        case "merged": String(localized: "tasks.pullRequest.merged")
        case "closed": String(localized: "tasks.pullRequest.closed")
        default: state
        }
    }
}
