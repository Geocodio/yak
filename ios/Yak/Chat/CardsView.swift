import SwiftUI
import YakKit

/// The surface every card of the chat sits on: a neutral tint over the whole card.
private struct CardSurface: ViewModifier {
    func body(content: Content) -> some View {
        content
            .padding(14).frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 14))
    }
}

extension View {
    fileprivate func cardSurface() -> some View { modifier(CardSurface()) }
}

/// The words for a server state such as `needs_input`: the catalog's for the states the app knows, and for any
/// other the raw text capitalized, with spaces.
enum StateWording {
    static func text(_ raw: String) -> String {
        switch raw {
        case "queued": return String(localized: "state.queued")
        case "working": return String(localized: "state.working")
        case "waiting": return String(localized: "state.waiting")
        case "done": return String(localized: "state.done")
        case "failed": return String(localized: "state.failed")
        case "closed": return String(localized: "state.closed")
        case "running": return String(localized: "state.running")
        case "completed": return String(localized: "state.completed")
        case "needs_input": return String(localized: "state.needs_input")
        default:
            let spaced = raw.replacingOccurrences(of: "_", with: " ").replacingOccurrences(of: "-", with: " ")
            guard let first = spaced.first else { return "" }
            return first.uppercased() + spaced.dropFirst()
        }
    }
}

/// A sub-thread Yak started: its name, its state, what it is doing or found, and the files it changed.
struct ChildCardView: View {
    let card: ChildCard
    let openThread: (String) -> Void

    private var summary: String? {
        [card.resultSummary, card.step].compactMap { $0 }.first { !$0.isEmpty }
    }

    var body: some View {
        Button {
            openThread(card.childThreadId.value)
        } label: {
            HStack(alignment: .top, spacing: 8) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(verbatim: card.name).font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
                        StateBadge(text: StateWording.text(card.state))
                    }
                    if let summary {
                        Text(verbatim: summary).font(.subheadline).foregroundStyle(Color.yakMuted)
                            .multilineTextAlignment(.leading)
                    }
                    if let files = card.filesChanged, files > 0 {
                        HStack(spacing: 8) {
                            Text("chat.child.files \(files)")
                            if let added = card.additions, let removed = card.deletions {
                                Text("chat.edits.counts \(added) \(removed)").monospacedDigit()
                            }
                        }
                        .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    }
                }
                .fixedSize(horizontal: false, vertical: true)
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(Color.yakMuted)
                    .padding(.top, 4).accessibilityHidden(true)
            }
            .cardSurface().contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityHint("chat.child.open")
        .accessibilityIdentifier("child.\(card.childThreadId.value)")
    }
}

/// A repository Yak added to the thread: its name, its role and state, and what went wrong.
struct RepositoryCardView: View {
    let card: RepositoryCard

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(verbatim: card.repo).font(.subheadline.weight(.semibold))
                StateBadge(text: StateWording.text(card.state))
            }
            Text(card.role == "context" ? "chat.repository.context" : "chat.repository.changes")
                .font(.footnote).foregroundStyle(Color.yakMuted)
            if let error = card.error, !error.isEmpty {
                Text(verbatim: error).font(.subheadline).foregroundStyle(Color.yakDanger)
            }
        }
        .fixedSize(horizontal: false, vertical: true)
        .cardSurface()
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("repository.\(card.repo)")
    }
}

/// A short label on a pill, so a state reads as text and not as a color.
struct StateBadge: View {
    let text: String

    var body: some View {
        Text(verbatim: text).font(.caption.weight(.semibold)).foregroundStyle(.primary)
            .padding(.horizontal, 8).padding(.vertical, 2)
            .background(Color(.tertiarySystemFill), in: .capsule)
    }
}
