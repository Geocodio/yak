import SwiftUI
import YakKit

/// One designed card for each preview state that shows no page. A scroll view, at most 520 points wide.
struct PreviewCardView: View {
    let model: PreviewModel
    let card: PreviewCard

    private var status: PreviewStatus? { model.snapshot.status }
    /// The height of one line of a step's title, so the mark sits on the title's line.
    @ScaledMetric(relativeTo: .body) private var lineHeight: CGFloat = 22

    var body: some View {
        ZStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    content
                    actions
                }
                .frame(maxWidth: 520, alignment: .leading)
                .padding(Design.Space.pageInset)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .scrollBounceBehavior(.basedOnSize)
        }
        .background(.yakPage)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("preview.card.\(Self.identifier(of: card.kind))")
    }

    static func identifier(of kind: PreviewCard.Kind) -> String {
        switch kind {
        case .page: "page"
        case .waking: "waking"
        case .waitingForSlot: "waiting"
        case .setupRunning: "setup-running"
        case .failing: "failing"
        case .noManifest: "no-manifest"
        case .setupEnded: "setup-ended"
        case .invalidManifest: "invalid-manifest"
        case .destroyed: "destroyed"
        case .restoring: "restoring"
        case .restoreFailed: "restore-failed"
        case .closed: "closed"
        case .localMachine: "local-machine"
        case .unknown: "unknown"
        }
    }

    // MARK: Content

    @ViewBuilder private var content: some View {
        switch card.kind {
        case .page: EmptyView()
        case .waking(let steps): waking(steps)
        case .waitingForSlot:
            header("hourglass", "preview.waiting.title", "preview.waiting.body")
            IndeterminateLine()
        case .setupRunning(let isAdding):
            header(
                "sparkles", isAdding ? "preview.setup.adding.title" : "preview.setup.fixing.title",
                isAdding ? "preview.setup.adding.body" : "preview.setup.fixing.body")
            IndeterminateLine()
        case .failing: failing
        case .noManifest:
            header("doc.badge.gearshape", "preview.noManifest.title", "preview.noManifest.body")
            sample
            readOnlyNote("preview.readOnly.ask")
        case .setupEnded:
            header("doc.badge.gearshape", "preview.setupEnded.title", "preview.setupEnded.body")
            sample
            readOnlyNote("preview.readOnly.ask")
        case .invalidManifest:
            header("exclamationmark.triangle", "preview.invalid.title", "preview.invalid.body")
            errorText
            readOnlyNote("preview.readOnly.ask")
        case .destroyed(let isReadOnly, let tookTooLong, let neverHadSandbox):
            if tookTooLong { Text("preview.restore.tookTooLong").font(.yak(.subheadline, .medium)) }
            header(
                "archivebox", neverHadSandbox ? "preview.destroyed.never.title" : "preview.destroyed.title",
                neverHadSandbox ? "preview.destroyed.never.body" : "preview.destroyed.body")
            if isReadOnly { note("preview.destroyed.readOnly") }
        case .restoring:
            header("arrow.triangle.2.circlepath", "preview.restoring.title", "preview.restoring.body")
            IndeterminateLine()
        case .restoreFailed(let isReadOnly):
            header("exclamationmark.triangle", "preview.restoreFailed.title", "preview.restoreFailed.body")
            if isReadOnly { note("preview.restoreFailed.readOnly") }
        case .closed: header("lock", "preview.closed.title", "preview.closed.body")
        case .localMachine: header("laptopcomputer", "preview.local.title", "preview.local.body")
        case .unknown:
            header("questionmark.circle", "preview.unknown.title", "preview.unknown.body")
            if !card.actions.contains(.openInBrowser) { note("preview.unknown.update") }
        }
    }

    private func header(_ symbol: String, _ title: LocalizedStringKey, _ body: LocalizedStringKey) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Image(systemName: symbol).font(.yak(.title)).foregroundStyle(Color.yakMuted).accessibilityHidden(true)
            Text(title).font(.yak(.title3, .semibold)).accessibilityAddTraits(.isHeader)
            Text(body).foregroundStyle(Color.yakMuted)
        }
        .fixedSize(horizontal: false, vertical: true)
    }

    private func note(_ key: LocalizedStringKey) -> some View {
        Text(key).font(.yak(.subheadline, .medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
    }

    /// A line for people who cannot drive the thread, shown where a driver gets a button.
    @ViewBuilder private func readOnlyNote(_ key: LocalizedStringKey) -> some View {
        if !card.actions.contains(where: { if case .ask = $0 { true } else { false } }) { note(key) }
    }

    @ViewBuilder private var errorText: some View {
        if let error = status?.error, !error.isEmpty {
            Text(error).font(.yak(.subheadline, .medium)).foregroundStyle(Color.yakDanger)
                .textSelection(.enabled).fixedSize(horizontal: false, vertical: true)
        }
    }

    // MARK: Waking

    @ViewBuilder private func waking(_ steps: [PreviewStepStatus]) -> some View {
        let isStarting = steps.first == .done
        VStack(alignment: .leading, spacing: 8) {
            Text(isStarting ? "preview.starting.title" : "preview.waking.title").font(.yak(.title3, .semibold))
                .accessibilityAddTraits(.isHeader)
            if isStarting, let command = status?.coldStartCommand, !command.isEmpty {
                Text("preview.starting.body.command \(command)").foregroundStyle(Color.yakMuted)
            } else {
                Text(isStarting ? "preview.starting.body.default" : "preview.waking.body")
                    .foregroundStyle(Color.yakMuted)
            }
        }
        .fixedSize(horizontal: false, vertical: true)
        VStack(alignment: .leading, spacing: 12) {
            step("preview.step.wake", nil, steps[safe: 0])
            step("preview.step.start", nil, steps[safe: 1])
            step(
                "preview.step.wait",
                status?.port.map { (port: Int) -> LocalizedStringKey in "preview.step.port \(String(port))" },
                steps[safe: 2])
        }
        .accessibilityElement(children: .contain)
        if isStarting, let log = status?.log, !log.isEmpty {
            logCard(Array(log.suffix(40)), title: "preview.log.title")
        }
        Text("preview.waking.footer").font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
            .fixedSize(horizontal: false, vertical: true)
    }

    private func step(_ title: LocalizedStringKey, _ detail: LocalizedStringKey?, _ status: PreviewStepStatus?)
        -> some View
    {
        HStack(alignment: .top, spacing: Design.Space.medium) {
            Group {
                switch status ?? .pending {
                case .done: Image(systemName: "checkmark.circle.fill").foregroundStyle(.primary)
                case .active: ProgressView().controlSize(.small).accessibilityHidden(true)
                case .pending: Image(systemName: "circle").foregroundStyle(Color.yakMuted)
                }
            }
            .frame(width: Design.Size.stepColumn, height: lineHeight)
            .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).fontWeight(status == .active ? .semibold : .regular)
                if let detail { Text(detail).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted) }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityValue(Text(Self.stateWord(status)))
    }

    private static func stateWord(_ status: PreviewStepStatus?) -> LocalizedStringKey {
        switch status ?? .pending {
        case .done: "preview.step.done"
        case .active: "preview.step.active"
        case .pending: "preview.step.pending"
        }
    }

    // MARK: Failing

    @ViewBuilder private var failing: some View {
        VStack(alignment: .leading, spacing: 8) {
            Image(systemName: "exclamationmark.triangle.fill").font(.yak(.title)).foregroundStyle(Color.yakDanger)
                .accessibilityHidden(true)
            Text("preview.failing.title").font(.yak(.title3, .semibold)).accessibilityAddTraits(.isHeader)
            if let command = status?.coldStartCommand, !command.isEmpty {
                Text("preview.failing.body.command \(command)").foregroundStyle(Color.yakMuted)
            } else {
                Text("preview.failing.body").foregroundStyle(Color.yakMuted)
            }
        }
        .fixedSize(horizontal: false, vertical: true)
        errorText
        if let log = status?.log, !log.isEmpty { logCard(Array(log.suffix(40)), title: nil) }
        readOnlyNote("preview.readOnly.fix")
    }

    private func logCard(_ lines: [String], title: LocalizedStringKey?) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            if let title { Text(title).font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakMuted) }
            ScrollView(.horizontal) {
                VStack(alignment: .leading, spacing: 2) {
                    ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                        Text(line).font(.yak(.footnote, isMonospaced: true)).fixedSize()
                    }
                }
                .textSelection(.enabled)
                .padding(12)
            }
            .yakCardSurface(in: .rect(cornerRadius: Design.Radius.row))
            .accessibilityIdentifier("preview.log")
        }
    }

    private var sample: some View {
        Text("preview.sample").font(.yak(.footnote, isMonospaced: true)).textSelection(.enabled)
            .frame(maxWidth: .infinity, alignment: .leading).padding(12)
            .yakCardSurface(in: .rect(cornerRadius: Design.Radius.row))
    }

    // MARK: Actions

    @ViewBuilder private var actions: some View {
        let primary = card.actions.first
        let rest = Array(card.actions.dropFirst())
        if let primary {
            button(primary)
            if !rest.isEmpty {
                ViewThatFits(in: .horizontal) {
                    HStack(spacing: 12) { ForEach(rest, id: \.self) { button($0) } }
                    VStack(spacing: 12) { ForEach(rest, id: \.self) { button($0) } }
                }
            }
        }
    }

    private func button(_ action: PreviewAction) -> some View {
        let isBusy = model.isPerforming == action || Self.isPending(action)
        return Button {
            Task { await model.perform(action) }
        } label: {
            HStack(spacing: 8) {
                if isBusy { ProgressView().controlSize(.small).accessibilityHidden(true) }
                Text(Self.title(of: action, isPending: Self.isPending(action))).fixedSize(
                    horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity, minHeight: 36)
        }
        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
        .disabled(isBusy || model.isPerforming != nil)
        .accessibilityIdentifier(Self.identifier(of: action))
    }

    private static func isPending(_ action: PreviewAction) -> Bool {
        if case .ask(_, let isPending) = action { return isPending }
        return false
    }

    static func title(of action: PreviewAction, isPending: Bool) -> LocalizedStringKey {
        switch action {
        case .ask(let intent, _):
            if isPending { return "preview.action.asking" }
            return intent == .addPreviewManifest ? "preview.action.askAdd" : "preview.action.askFix"
        case .tryAgain: return "preview.action.tryAgain"
        case .restore: return "preview.action.restore"
        case .retryRestore: return "preview.action.retryRestore"
        case .openTerminal: return "preview.action.openTerminal"
        case .showConversation: return "preview.action.showConversation"
        case .openInBrowser: return "preview.action.openInSafari"
        case .editManifest: return "preview.action.editManifest"
        }
    }

    static func identifier(of action: PreviewAction) -> String {
        switch action {
        case .ask: "preview.ask"
        case .tryAgain: "preview.tryAgain"
        case .restore: "preview.restore"
        case .retryRestore: "preview.retryRestore"
        case .openTerminal: "preview.openTerminal"
        case .showConversation: "preview.showConversation"
        case .openInBrowser: "preview.openInSafari"
        case .editManifest: "preview.editManifest"
        }
    }
}

extension Array {
    fileprivate subscript(safe index: Int) -> Element? { indices.contains(index) ? self[index] : nil }
}

/// A thin line that runs back and forth. Under Reduce Motion it is a still, half-filled bar.
struct IndeterminateLine: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var isAtEnd = false

    var body: some View {
        GeometryReader { geometry in
            Capsule().fill(Color.primary.opacity(0.65)).frame(width: geometry.size.width * 0.35)
                .offset(x: reduceMotion || !isAtEnd ? 0 : geometry.size.width * 0.65)
        }
        .frame(height: 4)
        .background(Color.primary.opacity(0.12), in: .capsule)
        .clipShape(.capsule)
        .onAppear {
            guard !StillMotion.isOn(reduceMotion: reduceMotion) else { return }
            withAnimation(.easeInOut(duration: 1.1).repeatForever(autoreverses: true)) { isAtEnd = true }
        }
        .accessibilityHidden(true)
    }
}

/// A message card for a state of the pane itself: loading, offline, a page that did not load.
struct PreviewMessageCard<Actions: View>: View {
    let symbol: String
    let title: LocalizedStringKey
    let message: Text
    let identifier: String
    @ViewBuilder let actions: Actions

    var body: some View {
        ZStack {
            card
        }
        .background(.yakPage)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier(identifier)
    }

    private var card: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Image(systemName: symbol).font(.yak(.title)).foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                Text(title).font(.yak(.title3, .semibold)).accessibilityAddTraits(.isHeader)
                message.foregroundStyle(Color.yakMuted)
                actions.padding(.top, 4)
            }
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: 520, alignment: .leading).padding(Design.Space.pageInset)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .scrollBounceBehavior(.basedOnSize)
    }
}

/// The skeleton of the first status: a title bar and three step rows as shapes.
struct PreviewSkeleton: View {
    let isSlow: Bool
    let retry: () -> Void

    var body: some View {
        ZStack { skeleton }
            .background(.yakPage)
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("preview.card.checking")
    }

    private var skeleton: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                RoundedRectangle(cornerRadius: 6).fill(Color.primary.opacity(0.1)).frame(width: 220, height: 24)
                    .accessibilityHidden(true)
                ForEach(0..<3, id: \.self) { _ in
                    HStack(spacing: 12) {
                        Circle().fill(Color.primary.opacity(0.1)).frame(width: 24, height: 24)
                        RoundedRectangle(cornerRadius: 6).fill(Color.primary.opacity(0.1)).frame(height: 16)
                    }
                    .accessibilityHidden(true)
                }
                HStack(spacing: 8) {
                    if !isSlow { ProgressView().controlSize(.small).accessibilityHidden(true) }
                    Text(isSlow ? "preview.checking.slow" : "preview.checking").font(.yak(.subheadline, .medium))
                        .foregroundStyle(Color.yakMuted)
                }
                if isSlow {
                    Button(action: retry) { Text("common.retry") }
                        .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                        .accessibilityIdentifier("preview.retry")
                }
            }
            .frame(maxWidth: 520, alignment: .leading).padding(Design.Space.pageInset)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}
