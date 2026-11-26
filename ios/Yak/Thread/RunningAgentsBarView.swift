import SwiftUI
import YakKit

/// The slim bar above the message field while agents run: how many, View (scrolls the conversation to the newest
/// agent's card) and Stop all (asks first). It feeds its model from the thread show and from the page's reports, and
/// draws nothing when no agent runs or the thread is closed.
struct RunningAgentsBarView: View {
    let thread: ThreadModel
    @Environment(\.webConversation) private var web
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var model: RunningAgentsModel { thread.agents }

    private var line: RunningAgentsBarLine? {
        let show = thread.snapshot?.show
        let isClosed = show?.thread.status == .closed || thread.composer.bar.unavailable == .closed
        return RunningAgentsBarLine.make(
            bar: model.bar, canDrive: show?.canDrive == true, isClosed: isClosed, isOffline: thread.isOffline)
    }

    var body: some View {
        Group {
            if let line {
                content(line).transition(reduceMotion ? .opacity : .opacity.combined(with: .move(edge: .bottom)))
            }
        }
        .motion(Motion.panel, value: line?.count != nil)
        .onChange(of: web?.agentsReport, initial: true) { model.pageReported(web?.agentsReport) }
        .onChange(of: line?.count) { old, new in
            guard let old, RunningAgentsBar.announces(from: old, to: new ?? 0) else { return }
            var text = AttributedString(
                new == nil || new == 0
                    ? String(localized: "agents.announce.none") : String(localized: "agents.running \(new ?? 0)"))
            text.accessibilitySpeechAnnouncementPriority = .low
            AccessibilityNotification.Announcement(text).post()
        }
    }

    // MARK: Content

    @ViewBuilder private func content(_ line: RunningAgentsBarLine) -> some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: Design.Space.small))
            : AnyLayout(HStackLayout(alignment: .center, spacing: Design.Space.compact))
        VStack(alignment: .leading, spacing: Design.Space.tight) {
            layout {
                summary(line)
                if dynamicTypeSize.isAccessibilitySize == false { Spacer(minLength: Design.Space.small) }
                HStack(spacing: Design.Space.small) {
                    viewButton(line)
                    if line.showsStop { stopButton(line) }
                }
            }
            if let failure = line.failure { failureRow(failure, line: line) }
            if line.needsConnection { explanation(Text("chat.action.offline"), identifier: "agents.offline") }
        }
        .padding(.horizontal, Design.Space.small)
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("agents.bar")
    }

    private func summary(_ line: RunningAgentsBarLine) -> some View {
        HStack(spacing: Design.Space.compact) {
            PulsingDot().accessibilityHidden(true)
            Text("agents.running \(line.count)").font(.yak(.footnote, .medium)).foregroundStyle(Color.primary)
                .fixedSize(horizontal: false, vertical: true)
                .accessibilityIdentifier("agents.count")
        }
        .accessibilityElement(children: .combine)
    }

    private func viewButton(_ line: RunningAgentsBarLine) -> some View {
        Button {
            guard let fragment = line.newest else { return }
            web?.focusAgent(fragment: fragment, thread: thread.threadID)
        } label: {
            Text("agents.view").font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakAccent)
                .padding(.horizontal, Design.Space.snug)
                .frame(minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(.pressable).disabled(line.newest == nil || web == nil)
        .accessibilityHint(Text("agents.view.hint")).accessibilityIdentifier("agents.view")
    }

    private func stopButton(_ line: RunningAgentsBarLine) -> some View {
        @Bindable var bindable = model
        return Button {
            model.askToStop()
        } label: {
            HStack(spacing: Design.Space.small) {
                if line.isRequesting { ProgressView().controlSize(.small) }
                Text(line.isRequesting || line.isStopping ? "agents.stopping" : "agents.stop")
                    .font(.yak(.footnote, .semibold))
                    .foregroundStyle(Color.primary)
            }
            .padding(.horizontal, Design.Space.medium)
            .frame(minHeight: Design.Size.agentChip)
            .background(Color.yakBar, in: .rect(cornerRadius: Design.Radius.small))
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.small)
                    .strokeBorder(Color.yakBarBorder, lineWidth: Design.Space.line)
            )
            .frame(minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(.pressable).disabled(!line.stopIsEnabled)
        .opacity(line.stopIsEnabled || line.isRequesting || line.isStopping ? 1 : Design.disabledOpacity)
        .accessibilityHint(Text(line.needsConnection ? "chat.action.offline" : "agents.stop.hint"))
        .accessibilityIdentifier("agents.stop")
        .confirmationDialog(
            line.count == 1 ? Text("agents.confirm.title.one") : Text("agents.confirm.title \(line.count)"),
            isPresented: $bindable.isConfirming, titleVisibility: .visible
        ) {
            Button("agents.confirm.stop \(line.count)", role: .destructive) { Task { await model.stop() } }
                .accessibilityIdentifier("agents.confirm.stop")
            Button("common.cancel", role: .cancel) {}
                .accessibilityIdentifier("agents.confirm.cancel")
        } message: {
            line.count == 1 ? Text("agents.confirm.message.one") : Text("agents.confirm.message \(line.count)")
        }
    }

    private func failureRow(_ failure: AgentsStopFailure, line: RunningAgentsBarLine) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: Design.Space.small) {
            Image(systemName: "exclamationmark.circle").font(.yak(.footnote)).foregroundStyle(Color.yakDanger)
                .accessibilityHidden(true)
            Text(failureText(failure)).font(.yak(.footnote)).foregroundStyle(Color.primary)
                .fixedSize(horizontal: false, vertical: true).layoutPriority(1)
                .accessibilityIdentifier("agents.failure")
            if line.showsRetry && line.showsStop {
                Spacer(minLength: Design.Space.small)
                Button {
                    Task { await model.stop() }
                } label: {
                    Text("agents.retry").font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakAccent)
                        .frame(minHeight: Design.Size.control).contentShape(.rect)
                }
                .buttonStyle(.pressable).disabled(!line.stopIsEnabled).accessibilityIdentifier("agents.retry")
            }
        }
    }

    private func explanation(_ text: Text, identifier: String) -> some View {
        text.font(.yak(.footnote)).foregroundStyle(Color.primary.opacity(0.85))
            .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier(identifier)
    }

    private func failureText(_ failure: AgentsStopFailure) -> LocalizedStringKey {
        switch failure {
        case .offline: "agents.failure.offline"
        case .signedOut: "agents.failure.signedOut"
        case .forbidden: "agents.failure.forbidden"
        case .unavailable: "agents.failure.unavailable"
        case .tooManyRequests: "agents.failure.tooManyRequests"
        case .serverProblem: "agents.failure.serverProblem"
        }
    }
}

/// The blue dot that says agents are working: a slow pulse, still under Reduce Motion.
private struct PulsingDot: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isDim = false

    var body: some View {
        Circle().fill(Color.yakInfo).frame(width: Design.Size.agentDot, height: Design.Size.agentDot)
            .opacity(isDim ? Design.closedMarkOpacity : 1)
            .onAppear {
                guard !StillMotion.isOn(reduceMotion: reduceMotion) else { return }
                withAnimation(Motion.pulse) { isDim = true }
            }
    }
}
