import SwiftUI
import YakKit

/// What Yak is doing, above the bar of every pane but Chat. It shows the line it is given and decides nothing.
/// A tap opens Chat; a question opens it with the question card.
struct ActivityStrip: View {
    let line: ActivityLine
    let openChat: (_ focusComposer: Bool) -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        switch line {
        case .question(let count): questionChip(Text("question.chip \(count)"), identifier: "question.chip")
        case .needsYou: questionChip(Text("activity.needsYou"), identifier: "activity.needsYou")
        case .step, .working: strip
        }
    }

    private var text: String {
        switch line {
        case .working, .question, .needsYou: String(localized: "activity.working")
        case .step(let category, let label, let isFailed):
            switch category {
            case .command:
                String(localized: isFailed ? "activity.running.failed \(label)" : "activity.running \(label)")
            case .read: String(localized: isFailed ? "activity.reading.failed \(label)" : "activity.reading \(label)")
            case .search:
                String(localized: isFailed ? "activity.searching.failed \(label)" : "activity.searching \(label)")
            case .fetch:
                String(localized: isFailed ? "activity.fetching.failed \(label)" : "activity.fetching \(label)")
            }
        }
    }

    private var strip: some View {
        Button {
            openChat(true)
        } label: {
            HStack(spacing: 10) {
                ProgressView().controlSize(.small).accessibilityHidden(true)
                Text(verbatim: text).font(.subheadline).lineLimit(dynamicTypeSize.isAccessibilitySize ? 2 : 1)
                    .truncationMode(.tail).frame(maxWidth: .infinity, alignment: .leading)
                Text("activity.steer").font(.subheadline.weight(.bold)).fixedSize()
            }
            .padding(.horizontal, 14).frame(minHeight: 44)
            .background(Color(.secondarySystemBackground), in: .capsule)
            .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(Text("activity.strip.label \(text)"))
        .accessibilityIdentifier("activity.strip")
        .padding(.horizontal, 8)
        .transition(.opacity)
    }

    private func questionChip(_ title: Text, identifier: String) -> some View {
        Button {
            openChat(false)
        } label: {
            HStack(spacing: 8) {
                Circle().fill(Color.primary).frame(width: 8, height: 8).accessibilityHidden(true)
                title.font(.subheadline.weight(.medium)).lineLimit(2)
            }
            .padding(.horizontal, 14).frame(minHeight: 44)
            .background(Color(.secondarySystemBackground), in: .capsule)
            .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityIdentifier(identifier)
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 8)
        .transition(.opacity)
    }
}

/// The strip's form in the trailing strip: one button at the bottom.
struct ActivityStripButton: View {
    let line: ActivityLine
    let openChat: (_ focusComposer: Bool) -> Void

    var body: some View {
        Button {
            openChat({ if case .working = line { true } else if case .step = line { true } else { false } }())
        } label: {
            Image(
                systemName: isQuestion
                    ? "questionmark.bubble" : (isNeeded ? "exclamationmark.bubble" : "arrow.triangle.2.circlepath")
            )
            .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel(
            Text(isQuestion ? "question.chip.strip" : (isNeeded ? "activity.needsYou" : "activity.strip.compact"))
        )
        .accessibilityIdentifier(isQuestion ? "question.chip" : (isNeeded ? "activity.needsYou" : "activity.strip"))
    }

    private var isQuestion: Bool {
        if case .question = line { true } else { false }
    }

    private var isNeeded: Bool {
        if case .needsYou = line { true } else { false }
    }
}
