import SwiftUI
import YakKit

/// The bottom of the task screen: the decision a co-owner makes, or what stands in the way. Approve is the one
/// primary control, as the board draws it.
struct TaskDecisionBar<Model: CoOwnerDecisionHost>: View {
    let model: Model
    /// Drawn inside a card: no bar surface, no rust, and the card carries the words that explain each state.
    var isInCard = false
    /// The card's way to ask GitHub for the access again; the task screen has none.
    var checkAgain: (() -> Void)?
    let openWeb: (URL) -> Void
    let requestChanges: () -> Void

    @State private var isRejecting = false
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        if shows {
            VStack(alignment: .leading, spacing: 10) {
                if let message = model.message {
                    Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                        .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("task.decision.message")
                    if model.canRetryAfterMessage {
                        Button {
                            Task { await model.retry() }
                        } label: {
                            Text("common.retry")
                        }
                        .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.decision.messageRetry")
                    }
                }
                if model.isOffline, !isInCard {
                    Text("task.decision.offline").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        .accessibilityIdentifier("task.decision.offline")
                }
                content
            }
            .padding(.horizontal, isInCard ? 0 : 16).padding(.vertical, isInCard ? 0 : 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(isInCard ? Color.clear : Color(.systemBackground))
            .overlay(alignment: .top) { if !isInCard { Divider() } }
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("task.decision")
            .confirmationDialog("task.reject.title", isPresented: $isRejecting, titleVisibility: .visible) {
                Button("task.reject.confirm", role: .destructive) { Task { await model.decide(.reject) } }
                Button("task.reject.keep", role: .cancel) {}
            } message: {
                Text("task.reject.message")
            }
        }
    }

    private var shows: Bool {
        if case .none = model.decision { return model.message != nil }
        return true
    }

    @ViewBuilder private var content: some View {
        switch model.decision {
        case .none: EmptyView()
        case .decide: decide
        case .waitingForOthers(let asked):
            VStack(alignment: .leading, spacing: 10) {
                if !isInCard {
                    Text("task.decision.waiting \(asked.formatted(.list(type: .and)))").font(
                        .subheadline.weight(.medium))
                }
                button("task.decision.withdraw", identifier: "task.decision.withdraw") {
                    Task { await model.decide(.withdraw) }
                }
            }
        case .linkGitHub:
            VStack(alignment: .leading, spacing: 10) {
                if !isInCard { Text("task.decision.link").font(.subheadline.weight(.medium)) }
                if let url = model.linkGitHubURL {
                    button("task.decision.linkButton", identifier: "task.decision.link") { openWeb(url) }
                }
                if let checkAgain {
                    button("thread.card.checkAccessAgain", identifier: "task.decision.checkAgain", action: checkAgain)
                }
            }
        case .noWriteAccess:
            VStack(alignment: .leading, spacing: 10) {
                if !isInCard {
                    Text("task.decision.noWriteAccess \(model.repositoryName)").font(.subheadline.weight(.medium))
                        .accessibilityIdentifier("task.decision.noWriteAccess")
                }
                if let checkAgain {
                    button("thread.card.checkAgain", identifier: "task.decision.checkAgain", action: checkAgain)
                }
            }
        case .accessCheckFailed:
            VStack(alignment: .leading, spacing: 10) {
                if !isInCard { Text("task.decision.accessFailed").font(.subheadline.weight(.medium)) }
                Button {
                    if let checkAgain { checkAgain() } else { Task { await model.retry() } }
                } label: {
                    Text(checkAgain == nil ? "common.retry" : "thread.card.checkAgain")
                }
                .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.decision.retry")
            }
        case .decided(let status, let by):
            Text(Self.decidedText(status, by: by)).font(.subheadline.weight(.semibold))
                .accessibilityIdentifier("task.decision.decided")
        }
    }

    @ViewBuilder private var decide: some View {
        if model.isApproving {
            HStack(spacing: 10) {
                ProgressView()
                Text("task.approve.opening").font(.subheadline.weight(.semibold))
            }
            .accessibilityElement(children: .combine).accessibilityIdentifier("task.decision.approving")
        } else {
            if !isInCard, case let reasons = model.holdReasons, !reasons.isEmpty {
                Text(verbatim: reasons.joined(separator: " ")).font(.subheadline.weight(.medium))
                    .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
            }
            let blocked = model.isSending || model.isOffline
            let buttons = Group {
                approveButton
                Button(action: requestChanges) { Text("task.decision.requestChanges") }
                    .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.decision.requestChanges")
                Button {
                    isRejecting = true
                } label: {
                    Text("task.decision.reject").foregroundStyle(Color.yakDanger)
                }
                .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.decision.reject")
            }
            Group {
                if dynamicTypeSize.isAccessibilitySize {
                    VStack(spacing: 8) { buttons }
                } else {
                    HStack(spacing: 8) { buttons }.fixedSize(horizontal: false, vertical: true)
                }
            }
            .disabled(blocked)
        }
    }

    @ViewBuilder private var approveButton: some View {
        let approve = Button {
            Task { await model.decide(.approve) }
        } label: {
            Text("task.decision.approve")
        }
        if isInCard {
            approve.buttonStyle(FilledButtonStyle()).accessibilityIdentifier("task.decision.approve")
        } else {
            approve.buttonStyle(PrimaryButtonStyle()).accessibilityIdentifier("task.decision.approve")
        }
    }

    private func button(
        _ title: LocalizedStringKey, identifier: String, action: @escaping () -> Void
    ) -> some View {
        Button(action: action) { Text(title) }
            .buttonStyle(SecondaryButtonStyle()).disabled(model.isSending || model.isOffline)
            .accessibilityIdentifier(identifier)
    }

    static func decidedText(_ status: String, by: String?) -> String {
        switch status {
        case "approved":
            by.map { String(localized: "task.decided.approvedBy \($0)") } ?? String(localized: "task.decided.approved")
        case "changes_requested": String(localized: "task.decided.changesRequested")
        case "rejected": String(localized: "task.decided.rejected")
        case "withdrawn": String(localized: "task.decided.withdrawn")
        default: status
        }
    }
}
