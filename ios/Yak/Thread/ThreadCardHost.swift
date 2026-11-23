import SwiftUI
import YakKit

/// The one card above the composer. The server names it; this view draws only that one, with the buttons the
/// server's flags allow. The question card keeps its own model and view.
struct ThreadCardHost: View {
    let thread: ThreadModel
    let paneHeight: CGFloat
    let openWeb: (URL) -> Void
    /// Shows the Files pane; nil when the thread has none.
    var openFiles: (() -> Void)?

    @State private var isRequestingChanges = false
    @State private var isTagging = false
    @State private var isExpanded = false
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var cards: ThreadCardModel { thread.cards }
    private var canDrive: Bool { thread.snapshot?.show?.canDrive ?? false }

    var body: some View {
        Group {
            switch cards.state {
            case .none, .question, .unknown:
                QuestionCard(model: thread.question, isOffline: thread.isOffline, paneHeight: paneHeight)
                    .motion(Motion.panel, value: thread.question.flow != nil)
            case .repositoryQuestion(let question, let canAnswer): repositoryQuestion(question, canAnswer: canAnswer)
            case .earlyWarning(let row, let notice): earlyWarning(row, notice)
            case .coOwner(let decision, let panel): coOwner(decision, panel)
            case .stopped(let stop, let canRetry, let canSetUp, let moveTargets):
                stopped(stop, canRetry: canRetry, canSetUp: canSetUp, moveTargets: moveTargets)
            }
        }
        .sensoryFeedback(.success, trigger: cards.successCount)
        .sensoryFeedback(.error, trigger: cards.errorCount)
        .onDisappear { cards.stop() }
    }

    // MARK: Repository question

    private func repositoryQuestion(_ question: RepositoryQuestion, canAnswer: Bool) -> some View {
        CardContainer(kind: "repository_question", tone: .neutral, paneHeight: paneHeight) {
            Text(verbatim: question.prompt).font(.yak(.headline)).accessibilityAddTraits(.isHeader)
            Text("thread.card.repository.body").font(.yak(.subheadline))
        } pinned: {
            VStack(spacing: Design.Space.compact) {
                ForEach(question.options, id: \.self) { option in
                    Button {
                        Task { await cards.send(.answerRepository(repo: option)) }
                    } label: {
                        HStack(spacing: Design.Space.compact) {
                            Text(verbatim: option).font(.yak(.body, .medium)).multilineTextAlignment(.leading)
                            Spacer(minLength: 0)
                            if cards.sending == .answerRepository(repo: option) { ProgressView() }
                        }
                        .frame(minHeight: Design.Size.cardButton - 2 * Design.Space.snug)
                        .workBox().contentShape(.rect)
                    }
                    .buttonStyle(.plain)
                    .disabled(!canAnswer || cards.isSending || cards.isOffline)
                    .accessibilityIdentifier("thread.card.repository.option.\(option)")
                }
            }
            footer(hasButtons: canAnswer)
        }
    }

    // MARK: Early warning

    private func earlyWarning(_ row: EarlyWarningRow, _ notice: EarlyWarningNotice) -> some View {
        CardContainer(kind: "early_warning", tone: .warn, paneHeight: paneHeight) {
            Text("thread.card.early.title").font(.yak(.headline)).accessibilityAddTraits(.isHeader)
            Text("thread.card.early.body").font(.yak(.subheadline))
            if let first = notice.areas.first {
                VStack(alignment: .leading, spacing: Design.Space.nudge) {
                    HStack(spacing: Design.Space.small) {
                        Text(verbatim: first.name).font(.yak(.body))
                        TonePill(text: Self.riskText(first.risk), tone: Self.riskTone(first.risk))
                    }
                    ForEach(Array(notice.areas.dropFirst().enumerated()), id: \.offset) { _, area in
                        Text(verbatim: "\(area.name), \(Self.riskText(area.risk).lowercased())").font(.yak(.footnote))
                            .foregroundStyle(Color.yakCardSecondaryText)
                    }
                }
                .workBox().accessibilityElement(children: .combine)
            }
        } pinned: {
            switch row {
            case .pick:
                VStack(spacing: Design.Space.compact) {
                    Button {
                        isTagging = true
                    } label: {
                        Text("thread.card.early.pick")
                    }
                    .buttonStyle(WorkButtonStyle(kind: .primary)).accessibilityIdentifier("thread.card.early.pick")
                    Button {
                        Task { await cards.decide(.decline) }
                    } label: {
                        buttonLabel("thread.card.early.notNow", isRunning: cards.sending == .decline)
                    }
                    .buttonStyle(WorkButtonStyle(kind: .secondary)).accessibilityIdentifier("thread.card.early.notNow")
                }
                .disabled(cards.isSending || cards.isOffline)
            case .asked(let name):
                Text("task.asked \(name)").font(.yak(.subheadline, .medium))
                    .accessibilityIdentifier("thread.card.early.asked")
            case .none: EmptyView()
            }
            footer(hasButtons: row != .none)
        }
        .sheet(isPresented: $isTagging) {
            if case .pick(let fallbackName) = row {
                TagCoOwnerSheet(model: cards, fallbackName: fallbackName, close: { isTagging = false })
                    .presentationDetents([.large]).presentationBackground(Color(.systemBackground))
            }
        }
    }

    private static func riskText(_ risk: String) -> String {
        switch risk {
        case "high": String(localized: "thread.card.early.risk.high")
        case "medium": String(localized: "thread.card.early.risk.medium")
        case "low": String(localized: "thread.card.early.risk.low")
        default: StateWording.text(risk)
        }
    }

    private static func riskTone(_ risk: String) -> Tone {
        switch risk {
        case "high": .bad
        case "medium": .warn
        default: .neutral
        }
    }

    // MARK: Co-owner

    private func coOwner(_ decision: TaskDecisionState, _ panel: CoOwnerPanel) -> some View {
        let repo = cards.repositoryName
        return Group {
            if case .decided = decision {
                EmptyView()
            } else {
                CardContainer(kind: "co_owner", tone: .warn, paneHeight: paneHeight) {
                    coOwnerWords(decision, panel, repo: repo)
                } pinned: {
                    TaskDecisionBar(
                        model: cards, isInCard: true,
                        checkAgain: { Task { await cards.checkAccess() } },
                        openFiles: openFiles, openWeb: openWeb, requestChanges: { isRequestingChanges = true })
                    if cards.isOffline, !decision.isNothingToDecide {
                        Text("thread.card.offline.decide").font(.yak(.footnote, .medium)).foregroundStyle(
                            Color.yakCardSecondaryText
                        )
                        .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.card.offline")
                    }
                    if cards.isApprovalSlow {
                        Text("thread.card.approveSlow").font(.yak(.footnote, .medium)).foregroundStyle(
                            Color.yakCardSecondaryText
                        )
                        .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.card.slow")
                    }
                    footer(hasButtons: !decision.isNothingToDecide)
                }
                .sheet(isPresented: $isRequestingChanges) {
                    RequestChangesSheet(model: cards, close: { isRequestingChanges = false })
                        .presentationDetents([.medium, .large]).presentationBackground(Color(.systemBackground))
                }
            }
        }
    }

    @ViewBuilder private func coOwnerWords(_ decision: TaskDecisionState, _ panel: CoOwnerPanel, repo: String)
        -> some View
    {
        switch decision {
        case .waitingForOthers(let asked):
            Text("thread.card.coOwner.waiting \(asked.formatted(.list(type: .and)))").font(.yak(.headline))
                .accessibilityAddTraits(.isHeader)
            Text("thread.card.coOwner.waitingBody").font(.yak(.subheadline))
        case .linkGitHub:
            Text("thread.card.coOwner.linkTitle").font(.yak(.headline)).accessibilityAddTraits(.isHeader)
            Text("thread.card.coOwner.linkBody \(repo)").font(.yak(.subheadline))
        default:
            Text("thread.card.coOwner.title").font(.yak(.headline)).accessibilityAddTraits(.isHeader)
            switch decision {
            case .noWriteAccess:
                Text("thread.card.coOwner.noAccess \(repo)").font(.yak(.subheadline))
                VStack(alignment: .leading, spacing: Design.Space.nudge) {
                    if let login = panel.viewer.login {
                        Text("thread.card.coOwner.linkedAs \(login)").font(.yak(.body))
                    }
                    Text("thread.card.coOwner.askAdmin").font(.yak(.footnote, .medium)).foregroundStyle(
                        Color.yakCardSecondaryText)
                }
                .workBox()
            default:
                Text(
                    verbatim: ([panel.holdReasons.joined(separator: " ")].filter { !$0.isEmpty }
                        + [askedLine(panel, decision)].compactMap { $0 }).joined(separator: " ")
                )
                .font(.yak(.subheadline)).fixedSize(horizontal: false, vertical: true)
                if !panel.filePaths.isEmpty {
                    let more = max(
                        (panel.stat?.files ?? panel.filePaths.count) - min(Self.shownFileCount, panel.filePaths.count),
                        0)
                    VStack(alignment: .leading, spacing: Design.Space.nudge) {
                        ForEach(panel.filePaths.prefix(Self.shownFileCount), id: \.self) { path in
                            Text(verbatim: path).font(.yak(.footnote, isMonospaced: true))
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        if more > 0 {
                            moreFilesLink(more)
                        }
                    }
                    .workBox().accessibilityElement(children: .combine)
                } else if let stat = panel.stat {
                    Text("thread.card.coOwner.files \(stat.files) \(stat.additions) \(stat.deletions)")
                        .font(.yak(.footnote, isMonospaced: true))
                        .workBox()
                }
                if case .accessCheckFailed = decision {
                    VStack(alignment: .leading, spacing: Design.Space.nudge) {
                        Text("thread.card.coOwner.checkFailed").font(.yak(.subheadline, .semibold))
                        Text("thread.card.coOwner.checkFailedBody").font(.yak(.footnote, .medium))
                            .foregroundStyle(Color.yakCardSecondaryText)
                    }
                    .workBox(fill: Tone.bad.surface)
                }
            }
        }
    }

    @ViewBuilder private func moreFilesLink(_ more: Int) -> some View {
        let label = Text("thread.card.coOwner.moreFiles \(more)").font(.yak(.subheadline, .medium))
            .fixedSize(horizontal: false, vertical: true)
        if let openFiles {
            Button(action: openFiles) { label.frame(minHeight: Design.Size.control, alignment: .leading) }
                .buttonStyle(.plain)
                .accessibilityIdentifier("thread.card.coOwner.moreFiles")
        } else {
            label
        }
    }

    /// The changed files a co-owner card lists before it says how many more there are.
    private static let shownFileCount = 2

    private func askedLine(_ panel: CoOwnerPanel, _ decision: TaskDecisionState) -> String? {
        guard !panel.requesterName.isEmpty else { return nil }
        return String(localized: "thread.card.coOwner.askedBy \(panel.requesterName)")
    }

    // MARK: Stopped

    private func stopped(_ stop: ThreadStop, canRetry: Bool, canSetUp: Bool, moveTargets: [String]) -> some View {
        let tone: Tone =
            switch stop.kind {
            case .yakStopped: .bad
            case .needsYou, .yakRetrying: .warn
            default: .neutral
            }
        let hasButtons = canRetry || canSetUp || !moveTargets.isEmpty
        return CardContainer(kind: "stopped", tone: tone, paneHeight: paneHeight) {
            Text(verbatim: stop.summary).font(.yak(.headline)).accessibilityAddTraits(.isHeader)
            if let detail = stop.detail, !detail.isEmpty {
                DisclosureButton(isExpanded: isExpanded, toggle: { isExpanded.toggle() }) {
                    Text("thread.card.stopped.details").font(.yak(.subheadline, .medium))
                }
                .accessibilityIdentifier("thread.card.stopped.details")
                if isExpanded {
                    Text(verbatim: detail).font(.yak(.subheadline)).fixedSize(horizontal: false, vertical: true)
                }
            }
            Text(verbatim: stop.nextStep).font(.yak(.subheadline, .medium))
                .fixedSize(horizontal: false, vertical: true)
        } pinned: {
            if hasButtons {
                VStack(spacing: Design.Space.compact) {
                    if canRetry || canSetUp {
                        let action: ThreadWorkAction = canSetUp ? .setUpAndContinue : .retry
                        Button {
                            Task { await cards.send(action) }
                        } label: {
                            buttonLabel(
                                canSetUp ? "thread.card.stopped.setUp" : "common.retry",
                                isRunning: cards.sending == action, isOnFill: true)
                        }
                        .buttonStyle(WorkButtonStyle(kind: .primary)).accessibilityIdentifier(
                            "thread.card.stopped.retry")
                    }
                    if !moveTargets.isEmpty { moveMenu(moveTargets) }
                }
                .disabled(cards.isSending || cards.isOffline)
            }
            footer(hasButtons: hasButtons)
        }
    }

    private func moveMenu(_ targets: [String]) -> some View {
        Menu {
            ForEach(targets, id: \.self) { target in
                Button {
                    Task { await cards.send(.move(repo: target)) }
                } label: {
                    Text("thread.card.move \(target)")
                }
            }
        } label: {
            buttonLabel("thread.card.stopped.moveElsewhere", isRunning: isMoving)
                .font(.yak(.headline)).foregroundStyle(.primary)
                .frame(maxWidth: .infinity, minHeight: Design.Size.cardButton)
                .background(Color.yakCardButton, in: .rect(cornerRadius: Design.Radius.key))
        }
        .tint(.primary)
        .accessibilityIdentifier("thread.card.stopped.move")
    }

    private var isMoving: Bool {
        if case .move? = cards.sending { true } else { false }
    }

    // MARK: Parts

    private func buttonLabel(_ title: LocalizedStringKey, isRunning: Bool, isOnFill: Bool = false) -> some View {
        HStack(spacing: Design.Space.compact) {
            if isRunning { ProgressView().tint(isOnFill ? Color(.systemBackground) : nil) }
            Text(title)
        }
    }

    /// The lines under a card's buttons: why a reader has none, that the page was already answered, and a failure
    /// of the last action with a way to try again.
    @ViewBuilder private func footer(hasButtons: Bool) -> some View {
        if !canDrive {
            Text("thread.card.reader").font(.yak(.footnote, .medium)).foregroundStyle(Color.yakCardSecondaryText)
                .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.card.reader")
        } else if cards.isOffline, hasButtons, cards.state.coOwnerDecision == nil {
            Text("thread.card.offline.other").font(.yak(.footnote, .medium)).foregroundStyle(
                Color.yakCardSecondaryText
            )
            .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.card.offline")
        }
        if cards.isAlreadyAnswered {
            Text("thread.card.alreadyAnswered").font(.yak(.footnote, .semibold))
                .accessibilityIdentifier("thread.card.alreadyAnswered")
        }
        if cards.state.coOwnerDecision == nil, let message = cards.message {
            Text(verbatim: message).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakDanger)
                .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.card.message")
            if cards.canRetryAfterMessage {
                Button {
                    Task { await cards.retry() }
                } label: {
                    Text("common.retry")
                }
                .buttonStyle(WorkButtonStyle(kind: .secondary)).accessibilityIdentifier("thread.card.messageRetry")
            }
        }
    }
}

/// Offers its one child at most `limit` points of height and is as tall as the child answers, so a child that scrolls
/// fills the limit and a child that fits keeps its own height.
private struct HeightCap: Layout {
    let limit: CGFloat

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        guard let child = subviews.first else { return .zero }
        return child.sizeThatFits(ProposedViewSize(width: proposal.width, height: min(proposal.height ?? limit, limit)))
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        subviews.first?.place(at: bounds.origin, proposal: ProposedViewSize(width: bounds.width, height: bounds.height))
    }
}

/// A scrolling area as tall as its content, or as tall as it is offered when that is less. Its first child is an
/// invisible copy of the content, which only tells the content's own height; its second is the scroll view.
private struct CappedScroll: Layout {
    private func contentHeight(_ subviews: Subviews, width: CGFloat) -> CGFloat {
        subviews[0].sizeThatFits(ProposedViewSize(width: width, height: nil)).height
    }

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? 0
        let natural = contentHeight(subviews, width: width)
        return CGSize(width: width, height: min(natural, proposal.height ?? natural))
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        subviews[0].place(at: bounds.origin, proposal: ProposedViewSize(width: bounds.width, height: bounds.height))
        subviews[1].place(at: bounds.origin, proposal: ProposedViewSize(width: bounds.width, height: bounds.height))
    }
}

/// The surface of a card: the tone's whole surface. The text is as tall as it is and scrolls when the pane has less
/// room; the buttons under it stay in view.
private struct CardContainer<Content: View, Pinned: View>: View {
    let kind: String
    let tone: Tone
    let paneHeight: CGFloat
    @ViewBuilder let content: Content
    @ViewBuilder let pinned: Pinned
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    /// The card never takes more than its share, and never so much that the chat falls under its least height.
    private var limit: CGFloat {
        max(min(paneHeight * share, paneHeight - Design.Card.chatLeastHeight), Design.Card.leastHeight)
    }

    private var share: CGFloat {
        if dynamicTypeSize.isAccessibilitySize { return Design.Card.largeShare }
        return paneHeight < Design.Card.shortPane ? Design.Card.shortShare : Design.Card.heightShare
    }

    private func text() -> some View {
        VStack(alignment: .leading, spacing: Design.Space.snug) { content }
            .frame(maxWidth: .infinity, alignment: .leading)
            .fixedSize(horizontal: false, vertical: true)
    }

    private var buttons: some View {
        VStack(alignment: .leading, spacing: Design.Space.snug) { pinned }
            .frame(maxWidth: .infinity, alignment: .leading)
            .fixedSize(horizontal: false, vertical: true)
    }

    var body: some View {
        HeightCap(limit: limit) {
            VStack(alignment: .leading, spacing: Design.Space.snug) {
                CappedScroll {
                    text().opacity(0).accessibilityHidden(true).allowsHitTesting(false)
                    ScrollView { text() }.scrollBounceBehavior(.basedOnSize)
                }
                // The buttons keep their height first; when even they need more than the card has, they scroll.
                HeightCap(limit: limit - Design.Card.textLeastHeight) {
                    ViewThatFits(in: .vertical) {
                        buttons
                        ScrollView { buttons }.scrollBounceBehavior(.basedOnSize)
                    }
                }
                .layoutPriority(1)
            }
        }
        .id(dynamicTypeSize)
        .workCardSurface(tone: tone)
        .padding(.horizontal, Design.Space.compact).padding(.bottom, Design.Space.compact)
        .layoutPriority(1)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.card.\(kind)")
    }
}

extension ThreadCardState {
    /// The decision of a co-owner card; nil for every other card.
    var coOwnerDecision: TaskDecisionState? {
        if case .coOwner(let decision, _) = self { decision } else { nil }
    }
}

extension TaskDecisionState {
    /// Nothing is offered because the viewer cannot drive.
    fileprivate var isNothingToDecide: Bool { self == .none }
}
