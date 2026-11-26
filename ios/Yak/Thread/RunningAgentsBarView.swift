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
        .onChange(of: thread.isOffline, initial: true) { model.connectivityChanged(isOffline: thread.isOffline) }
        .onChange(of: model.bar.count) { old, new in
            guard RunningAgentsBar.announces(from: old, to: new) else { return }
            announce(new == 0 ? String(localized: "agents.announce.none") : String(localized: "agents.running \(new)"))
        }
        .onChange(of: model.bar.phase) { _, phase in
            if case .failed(let failure) = phase { announce(String(localized: failureText(failure))) }
        }
        .onDisappear { model.isConfirming = false }
    }

    /// Said politely, after what VoiceOver is already reading.
    private func announce(_ sentence: String) {
        var text = AttributedString(sentence)
        text.accessibilitySpeechAnnouncementPriority = .low
        AccessibilityNotification.Announcement(text).post()
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
            if let failure = line.failure { failureRow(failure) }
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
        }
        .accessibilityElement(children: .combine).accessibilityIdentifier("agents.count")
    }

    private func viewButton(_ line: RunningAgentsBarLine) -> some View {
        Button {
            guard let fragment = line.newest else { return }
            web?.focusAgent(fragment: fragment, thread: thread.threadID)
        } label: {
            Text("agents.view").font(.yak(.footnote, .semibold))
                .foregroundStyle(line.newest == nil || web == nil ? Color.yakMuted : Color.yakAction)
                .padding(.horizontal, Design.Space.snug)
                .frame(minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(UndimmedButtonStyle()).disabled(line.newest == nil || web == nil)
        .accessibilityHint(Text("agents.view.hint")).accessibilityIdentifier("agents.view")
    }

    /// The chip: Stop all, or Try again after a failure that trying again can change; while the request runs and while
    /// the agents wind down, a quiet label that cannot be pressed.
    private func stopButton(_ line: RunningAgentsBarLine) -> some View {
        @Bindable var bindable = model
        let isWorking = line.isRequesting || line.isStopping
        return Group {
            if isWorking {
                chip(line).accessibilityElement(children: .combine).accessibilityLabel(Text("agents.stopping"))
                    .accessibilityAddTraits(.updatesFrequently).accessibilityIdentifier("agents.stop")
            } else {
                Button {
                    // A retry was confirmed already; a first stop is asked about.
                    if line.showsRetry { Task { await model.stop() } } else { model.askToStop() }
                } label: {
                    chip(line)
                }
                .buttonStyle(UndimmedButtonStyle()).disabled(!line.stopIsEnabled)
                .accessibilityHint(
                    Text(
                        line.needsConnection
                            ? "chat.action.offline" : line.showsRetry ? "agents.retry.hint" : "agents.stop.hint")
                )
                .accessibilityIdentifier(line.showsRetry ? "agents.retry" : "agents.stop")
            }
        }
        .alert(
            line.count == 1 ? Text("agents.confirm.title.one") : Text("agents.confirm.title \(line.count)"),
            isPresented: $bindable.isConfirming
        ) {
            // Cancel is the default button; the destructive one is never what Return would press.
            Button("common.cancel", role: .cancel) {}
            Button("agents.confirm.stop \(line.count)", role: .destructive) { Task { await model.stop() } }
                .accessibilityIdentifier("agents.confirm.stop")
        } message: {
            line.count == 1 ? Text("agents.confirm.message.one") : Text("agents.confirm.message \(line.count)")
        }
    }

    private func chip(_ line: RunningAgentsBarLine) -> some View {
        HStack(spacing: Design.Space.small) {
            if line.isRequesting { ProgressView().controlSize(.small) }
            Text(
                line.isRequesting || line.isStopping
                    ? "agents.stopping" : line.showsRetry ? "agents.retry" : "agents.stop"
            )
            .font(.yak(.footnote, .semibold))
            .foregroundStyle(
                !line.stopIsEnabled && !line.isRequesting && !line.isStopping
                    ? Color.yakMuted : line.showsRetry ? Color.yakAction : Color.primary)
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

    private func failureRow(_ failure: AgentsStopFailure) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: Design.Space.small) {
            Image(systemName: "exclamationmark.circle").font(.yak(.footnote)).foregroundStyle(Color.yakDanger)
                .accessibilityHidden(true)
            Text(failureText(failure)).font(.yak(.footnote)).foregroundStyle(Color.primary)
                .fixedSize(horizontal: false, vertical: true).layoutPriority(1)
                .accessibilityIdentifier("agents.failure")
        }
    }

    private func explanation(_ text: Text, identifier: String) -> some View {
        text.font(.yak(.footnote)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier(identifier)
    }

    private func failureText(_ failure: AgentsStopFailure) -> LocalizedStringResource {
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

/// The blue dot that says agents are working: a slow pulse, still while Reduce Motion is on.
private struct PulsingDot: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isDim = false

    var body: some View {
        Circle().fill(Color.yakInfo).frame(width: Design.Size.agentDot, height: Design.Size.agentDot)
            .opacity(isDim ? Design.agentDotDimOpacity : 1)
            .onAppear { update() }
            .onChange(of: reduceMotion) { update() }
    }

    private func update() {
        if StillMotion.isOn(reduceMotion: reduceMotion) {
            var transaction = Transaction(animation: nil)
            transaction.disablesAnimations = true
            withTransaction(transaction) { isDim = false }
        } else {
            withAnimation(Motion.pulse) { isDim = true }
        }
    }
}

/// A pressed button that stays as readable when it is disabled: the bar's disabled controls change their text colour,
/// because a dimmed label fails the contrast audit.
private struct UndimmedButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.pressFeedback(configuration.isPressed)
    }
}
