import SwiftUI
import YakKit

extension Color {
    /// The terminal's near-black surface in both appearances.
    static let terminalBackground = Color.yakTerminal
    static let terminalText = Color(red: 0.9, green: 0.9, blue: 0.91)
    static let terminalMuted = Color(red: 0.9, green: 0.9, blue: 0.91).opacity(0.78)
}

/// A neutral button on the terminal surface. Only "Open PR" is rust-colored on a thread screen.
struct TerminalActionStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.yak(.headline)).foregroundStyle(Color.black)
            .padding(.horizontal, 20).frame(minHeight: 44)
            .background(Color.terminalText.opacity(configuration.isPressed ? 0.7 : 1), in: .capsule)
            .opacity(isEnabled ? 1 : 0.6)
    }
}

/// What the full pane says while the focused shell has not connected yet.
struct TerminalStateView: View {
    let model: TerminalPaneModel
    @Environment(\.isNetworkOnline) private var isOnline

    private struct Content {
        var title: LocalizedStringKey
        var detail: Text?
        var action: (title: LocalizedStringKey, run: () -> Void)?
        var showsSteps = false
        var waitingSince: Date?
    }

    var body: some View {
        let state = content
        ScrollView {
            VStack(spacing: 14) {
                if state.showsSteps { StepRows(model: model) } else { Spacer(minLength: 40) }
                Text(state.title).font(.yak(.title3, .semibold)).multilineTextAlignment(.center)
                    .accessibilityAddTraits(.isHeader)
                if let detail = state.detail {
                    detail.font(.yak(.body)).foregroundStyle(Color.terminalMuted).multilineTextAlignment(.center)
                }
                if let since = state.waitingSince { WaitingElapsed(since: since) }
                if let message = model.restoreMessage {
                    Text(message).font(.yak(.subheadline, .medium)).foregroundStyle(Color.terminalMuted)
                        .multilineTextAlignment(.center)
                }
                if let action = state.action {
                    Button {
                        action.run()
                    } label: {
                        HStack(spacing: 8) {
                            if model.isRestoring { ProgressView().tint(.black) }
                            Text(action.title)
                        }
                    }
                    .buttonStyle(TerminalActionStyle()).disabled(model.isRestoring)
                    .accessibilityIdentifier("terminal.state.action")
                }
            }
            .foregroundStyle(Color.terminalText)
            .padding(Design.Space.pageInset).frame(maxWidth: 520, alignment: .leading)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .scrollBounceBehavior(.basedOnSize)
        .background(Color.terminalBackground, ignoresSafeAreaEdges: [])
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("terminal.state")
    }

    private func newShell() { Task { await model.newShell() } }
    private func retry() { Task { await model.retry() } }
    private func restore() { Task { await model.restore() } }

    // swiftlint:disable:next cyclomatic_complexity
    private var content: Content {
        guard model.hasFacts else { return Content(title: "terminal.opening.title", showsSteps: true) }
        guard let snapshot = model.focusedSnapshot else {
            return Content(
                title: "terminal.none.title", action: ("terminal.shell.new", newShell))
        }
        switch snapshot.phase {
        case .idle:
            return Content(title: "terminal.opening.title", showsSteps: true)
        case .opening(_, _, let isSlow):
            let title: LocalizedStringKey =
                switch model.sandboxState {
                case "paused": "terminal.waking.title"
                case "stopped": "terminal.starting.title"
                default: "terminal.opening.title"
                }
            var content = Content(title: title, showsSteps: true)
            switch model.sandboxState {
            case "paused": content.detail = Text("terminal.waking.detail")
            case "stopped": content.detail = Text("terminal.starting.detail")
            default: break
            }
            if isSlow {
                content.detail = Text("terminal.slow")
                content.action = ("terminal.retry", retry)
            }
            return content
        case .waitingForSlot(let limit, let began):
            return Content(
                title: "terminal.waiting.title", detail: waitingDetail(limit), showsSteps: true, waitingSince: began)
        case .waitedTooLong:
            return Content(
                title: "terminal.waited.title", detail: Text("terminal.waited.detail"),
                action: ("terminal.retry", retry))
        case .restoring:
            return Content(
                title: "terminal.restoring.title", detail: Text("terminal.restoring.detail"), showsSteps: true)
        case .stillRestoring:
            return Content(
                title: "terminal.stillRestoring.title", detail: Text("terminal.stillRestoring.detail"),
                action: ("terminal.retry", retry))
        case .refused(let refusal): return refused(refusal)
        case .failed(let problem): return failed(problem)
        default:
            return Content(title: "terminal.opening.title", showsSteps: true)
        }
    }

    private func waitingDetail(_ limit: TerminalWaitLimit?) -> Text {
        switch limit {
        case .personShare:
            if let count = model.personAwakeCap { return Text("terminal.waiting.person \(count)") }
            return Text("terminal.waiting.personUnknown")
        case .globalCap: return Text("terminal.waiting.global")
        case .memoryFloor: return Text("terminal.waiting.memory")
        default: return Text("terminal.waiting.other")
        }
    }

    private func refused(_ refusal: TerminalRefusal) -> Content {
        switch refusal {
        case .originNotAllowed(let message):
            return Content(
                title: "terminal.origin.title",
                detail: Text(verbatim: message + "\n\n" + String(localized: "terminal.origin.detail")))
        case .forbidden:
            return Content(title: "terminal.forbidden.title", detail: Text("terminal.forbidden.detail"))
        case .threadClosed:
            return Content(title: "terminal.closed.title", detail: Text("terminal.closed.detail"))
        case .onComputer:
            return Content(title: "terminal.computer.title", detail: Text("terminal.computer.detail"))
        case .sandboxRemoved:
            return Content(
                title: "terminal.removed.title", detail: Text("terminal.removed.detail"),
                action: ("terminal.restore", restore))
        case .noSandbox:
            return Content(title: "terminal.noSandbox.title", detail: Text("terminal.noSandbox.detail"))
        case .restoreFailed(let message):
            return Content(
                title: "terminal.restoreFailed.title", detail: Text(verbatim: message),
                action: ("terminal.tryAgain", restore))
        case .blocked(_, let message), .other(_, let message):
            return Content(
                title: "terminal.error.title", detail: Text(verbatim: message), action: ("terminal.retry", retry))
        }
    }

    private func failed(_ problem: ThreadProblem) -> Content {
        switch problem {
        case .offline:
            return Content(title: "terminal.offline.title", detail: Text("terminal.offline.detail"))
        case .server(_, let message):
            return Content(
                title: "terminal.error.title", detail: Text(verbatim: message), action: ("terminal.retry", retry))
        default:
            return Content(
                title: "terminal.error.title", detail: Text("terminal.error.detail"), action: ("terminal.retry", retry))
        }
    }
}

/// The steps of opening a shell: done rows have a check, the current one a spinner, later ones are dimmed.
private struct StepRows: View {
    let model: TerminalPaneModel

    private enum Mark { case done, current, later }

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { timeline in
            VStack(alignment: .leading, spacing: 10) {
                ForEach(Array(rows(now: timeline.date).enumerated()), id: \.offset) { _, row in
                    HStack(spacing: 10) {
                        switch row.mark {
                        case .done: Image(systemName: "checkmark.circle.fill").accessibilityHidden(true)
                        case .current: ProgressView().tint(.white).accessibilityHidden(true)
                        case .later: Image(systemName: "circle").accessibilityHidden(true)
                        }
                        row.title
                        if let elapsed = row.elapsed {
                            Text(verbatim: elapsed).monospacedDigit().foregroundStyle(Color.terminalMuted)
                        }
                    }
                    .font(.yak(.body, row.mark == .current ? .semibold : .regular))
                    .foregroundStyle(row.mark == .later ? Color.terminalMuted : Color.terminalText)
                    .accessibilityElement(children: .combine)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading).padding(.bottom, 8)
        }
        .accessibilityIdentifier("terminal.steps")
    }

    private struct Row {
        let title: Text
        let mark: Mark
        var elapsed: String?
    }

    private func rows(now: Date) -> [Row] {
        let phase = model.focusedSnapshot?.phase
        let running = model.sandboxState == "running"
        var step = 0
        var since: Date?
        var isRestoring = false
        switch phase {
        case .opening(let current, let began, _):
            step = current == .sandbox ? 0 : (current == .link ? 1 : 2)
            since = began
        case .waitingForSlot:
            break
        case .restoring(let began):
            since = began
            isRestoring = true
        default: break
        }
        func mark(_ index: Int) -> Mark { index < step ? .done : (index == step ? .current : .later) }
        let elapsed = since.map { Self.format(max(0, now.timeIntervalSince($0)), minutes: isRestoring) }
        let first: Text =
            isRestoring
            ? Text("terminal.step.restoring")
            : (running ? Text("terminal.step.ready") : Text("terminal.step.waking"))
        var result = [Row(title: first, mark: mark(0), elapsed: mark(0) == .current ? elapsed : nil)]
        if !isRestoring, !running {
            result.append(Row(title: Text("terminal.step.resumed"), mark: mark(0) == .done ? .done : .later))
        }
        result.append(Row(title: Text("terminal.step.link"), mark: mark(1)))
        result.append(Row(title: Text("terminal.step.shell"), mark: mark(2)))
        return result
    }

    private static func format(_ seconds: TimeInterval, minutes: Bool) -> String {
        let total = Int(seconds)
        return minutes ? TerminalElapsed.minutes(total) : String(localized: "terminal.step.seconds \(total)")
    }
}

/// Elapsed time as m:ss.
enum TerminalElapsed {
    static func minutes(_ total: Int) -> String { String(format: "%d:%02d", total / 60, total % 60) }
}

/// "Waiting m:ss" under the steps while the shell waits for a sandbox slot.
private struct WaitingElapsed: View {
    let since: Date

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { timeline in
            let elapsed = TerminalElapsed.minutes(max(0, Int(timeline.date.timeIntervalSince(since))))
            Text("terminal.waiting.elapsed \(elapsed)").font(.yak(.subheadline, .medium)).monospacedDigit()
                .foregroundStyle(Color.terminalMuted)
        }
        .accessibilityIdentifier("terminal.waiting.elapsed")
    }
}
