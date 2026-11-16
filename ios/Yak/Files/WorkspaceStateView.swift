import SwiftUI
import YakKit

/// What the Files pane and the file viewer say instead of content: a title, a sentence, an action and the elapsed
/// time of a wait.
struct WorkspaceStateContent {
    var title: LocalizedStringKey
    var detail: Text?
    var action: (title: LocalizedStringKey, identifier: String, run: () -> Void)?
    var elapsedSince: Date?
    var showsSkeleton = false

    /// The state of a sandbox that cannot serve files. `restore` runs the Restore button of a removed sandbox.
    static func availability(
        _ availability: WorkspaceAvailability, retry: @escaping () -> Void, restore: @escaping () -> Void
    )
        -> WorkspaceStateContent
    {
        switch availability {
        case .waking(let waitingForSlot, let restoring, let since):
            if restoring {
                return WorkspaceStateContent(
                    title: "files.restoring.title", detail: Text("files.restoring.detail"), elapsedSince: since)
            }
            if waitingForSlot {
                return WorkspaceStateContent(
                    title: "files.slot.title", detail: Text("files.slot.detail"), elapsedSince: since)
            }
            return WorkspaceStateContent(
                title: "files.waking.title", detail: Text("files.waking.detail"), elapsedSince: since)
        case .stillWaking:
            return WorkspaceStateContent(
                title: "files.stillWaking.title", detail: Text("files.stillWaking.detail"),
                action: ("files.retry", "files.state.action", retry))
        case .stillRestoring:
            return WorkspaceStateContent(
                title: "files.stillRestoring.title", detail: Text("files.stillRestoring.detail"),
                action: ("files.retry", "files.state.action", retry))
        case .asleep:
            return WorkspaceStateContent(title: "files.asleep.title", detail: Text("files.asleep.detail"))
        case .removed(let canRestore, _):
            if canRestore {
                return WorkspaceStateContent(
                    title: "files.removed.title", detail: Text("files.removed.detail"),
                    action: ("files.restore", "files.restore", restore))
            }
            return WorkspaceStateContent(title: "files.removed.title", detail: Text("files.removed.askSomeone"))
        case .closedRemoved:
            return WorkspaceStateContent(title: "files.closed.title")
        case .onComputer:
            return WorkspaceStateContent(title: "files.computer.title")
        case .other(let message):
            return WorkspaceStateContent(title: "files.error.title", detail: Text(verbatim: message))
        }
    }

    /// The sentence a failed save gives for an unavailable sandbox.
    static func sentence(for availability: WorkspaceAvailability) -> LocalizedStringKey {
        switch availability {
        case .asleep: "files.save.asleep"
        case .removed, .closedRemoved, .onComputer: "files.save.gone"
        default: "files.save.waking"
        }
    }

    static func problem(_ problem: ThreadProblem, retry: @escaping () -> Void) -> WorkspaceStateContent {
        switch problem {
        case .offline:
            return WorkspaceStateContent(title: "files.offline.title", detail: Text("files.offline.detail"))
        case .notFound:
            return WorkspaceStateContent(title: "files.notHere.title")
        case .server(_, let message):
            return WorkspaceStateContent(
                title: "files.error.title", detail: Text(verbatim: message),
                action: ("files.retry", "files.state.action", retry))
        default:
            return WorkspaceStateContent(
                title: "files.error.title", detail: Text("files.error.detail"),
                action: ("files.retry", "files.state.action", retry))
        }
    }
}

/// A centered state: the title, the sentence, the elapsed time and one action.
struct WorkspaceStateView: View {
    let content: WorkspaceStateContent
    var isBusy = false
    var message: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 14) {
                if content.showsSkeleton { SkeletonRows(count: 8) }
                Text(content.title).font(.title3.weight(.semibold)).multilineTextAlignment(.center)
                    .accessibilityAddTraits(.isHeader)
                if let detail = content.detail {
                    detail.font(.body).foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                }
                if let since = content.elapsedSince { ElapsedSeconds(since: since) }
                if let message {
                    Text(message).font(.subheadline.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.center)
                }
                if let action = content.action {
                    Button {
                        action.run()
                    } label: {
                        HStack(spacing: 8) {
                            if isBusy { ProgressView() }
                            Text(action.title)
                        }
                        .font(.headline).padding(.horizontal, 20).frame(minHeight: 44)
                        .background(Color.primary.opacity(0.12), in: .capsule)
                    }
                    .buttonStyle(.plain).disabled(isBusy)
                    .accessibilityIdentifier(action.identifier)
                }
            }
            .padding(24).frame(maxWidth: 520).frame(maxWidth: .infinity)
        }
        .scrollBounceBehavior(.basedOnSize)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.state")
    }
}

/// Elapsed seconds that tick once a second.
private struct ElapsedSeconds: View {
    let since: Date

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { timeline in
            let seconds = max(0, Int(timeline.date.timeIntervalSince(since)))
            Text("terminal.step.seconds \(seconds)").font(.subheadline.weight(.medium)).monospacedDigit()
                .foregroundStyle(Color.yakMuted)
        }
    }
}

/// Gray bars where rows will be; they appear only after 300 ms, so a quick answer never flashes them.
struct SkeletonRows: View {
    let count: Int
    @State private var isShown = false

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            ForEach(0..<count, id: \.self) { index in
                RoundedRectangle(cornerRadius: 6).fill(Color.primary.opacity(0.08))
                    .frame(width: CGFloat(120 + (index * 37) % 140), height: 16)
                    .padding(.leading, CGFloat((index % 3) * 22))
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 16).padding(.vertical, 8)
        .opacity(isShown ? 1 : 0)
        .accessibilityHidden(true)
        .task {
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            isShown = true
        }
    }
}
