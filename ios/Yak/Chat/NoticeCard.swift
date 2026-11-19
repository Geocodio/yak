import SwiftUI
import YakKit

/// What the server says about the thread itself, above the last row: a limit, a guard, a failed restore, an
/// account problem. The buttons are the server's own offers; every other kind shows its words alone.
struct NoticeCard: View {
    let notice: ThreadNotice
    let thread: ThreadModel

    private enum Action { case continueTurn, retryRestore, none }
    @Environment(SceneModel.self) private var scene: SceneModel?
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass

    private var action: Action {
        switch notice.kind {
        case "guard_turns", "guard_time": notice.canContinue ? .continueTurn : .none
        case "restore_failed": .retryRestore
        default: .none
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                if notice.kind == "restoring" || notice.kind == "waiting_for_sandbox" {
                    ProgressView().controlSize(.small).accessibilityHidden(true)
                } else {
                    Image(systemName: "exclamationmark.circle").foregroundStyle(Color.yakMuted)
                        .accessibilityHidden(true)
                }
                Text(verbatim: notice.title).font(.subheadline.weight(.semibold))
                    .fixedSize(horizontal: false, vertical: true)
            }
            if !notice.body.isEmpty {
                Text(verbatim: notice.body).font(.subheadline).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            if notice.kind == "usage_limit", let resetsAt = RelativeAge.parse(notice.resetsAt) {
                Countdown(until: resetsAt)
            }
            if notice.kind == "moved", let threadID = notice.threadId {
                Button {
                    scene?.open(thread: threadID, isCompactWidth: horizontalSizeClass == .compact)
                } label: {
                    Text("chat.notice.openMoved").fixedSize(horizontal: false, vertical: true)
                }
                .buttonStyle(WorkButtonStyle(kind: .primary)).accessibilityIdentifier("notice.moved.open")
            }
            switch action {
            case .continueTurn:
                button("chat.notice.continue", identifier: "notice.continue") { await thread.continueTurn() }
            case .retryRestore: button("common.retry", identifier: "notice.retry") { await thread.retryRestore() }
            case .none: EmptyView()
            }
        }
        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.row))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("notice.\(notice.kind)")
    }

    private func button(_ title: LocalizedStringKey, identifier: String, run: @escaping () async -> Void)
        -> some View
    {
        Button {
            Task { await run() }
        } label: {
            HStack(spacing: 8) {
                if thread.isNoticeActionRunning { ProgressView().controlSize(.small) }
                Text(title).fixedSize(horizontal: false, vertical: true)
            }
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
        .disabled(thread.isNoticeActionRunning)
        .accessibilityIdentifier(identifier)
    }
}

/// The time left until a limit lifts, counting each second.
private struct Countdown: View {
    let until: Date

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { context in
            let remaining = until.timeIntervalSince(context.date)
            Group {
                if remaining > 0 {
                    Text("chat.notice.resets \(ElapsedText.make(seconds: remaining))")
                } else {
                    Text("chat.notice.resetsNow")
                }
            }
            .font(.subheadline.weight(.medium)).monospacedDigit()
        }
        .accessibilityIdentifier("notice.countdown")
    }
}
