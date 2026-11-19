import SwiftUI
import YakKit

/// What Yak ran: one folded row for a group of calls, or the plain row of a single call.
struct ToolActivityView: View {
    let item: ConversationItem
    let isCurrentStep: Bool
    @Environment(\.chatStartsExpanded) private var startsExpanded
    @State private var isExpanded: Bool?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var expanded: Bool { isExpanded ?? startsExpanded }

    var body: some View {
        if item.steps.count == 1, let step = item.steps.first {
            StepRow(step: step, isCurrent: isCurrentStep, at: item.at)
        } else if !item.steps.isEmpty {
            VStack(alignment: .leading, spacing: 0) {
                groupRow
                if expanded {
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(item.steps, id: \.key) { step in
                            StepRow(step: step, isCurrent: isCurrentStep && step.isRunning, at: item.at)
                        }
                    }
                    .padding(.leading, 12)
                }
            }
        }
    }

    /// The folded row is the tinted one while the group runs and stays folded.
    private var groupRow: some View {
        let isTinted = isCurrentStep && !expanded
        return DisclosureButton(isExpanded: expanded, toggle: { isExpanded = !expanded }) {
            HStack(spacing: 8) {
                if isTinted { ProgressView().controlSize(.small).accessibilityHidden(true) }
                InlineOrStacked {
                    // The id is on the text, so a text size change redraws it while the row keeps its expanded state.
                    Text(verbatim: item.text ?? "").font(.subheadline).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
                    if isTinted, let start = RelativeAge.parse(item.at) { ElapsedView(since: start) }
                }
            }
        }
        .padding(.horizontal, isTinted ? 10 : 0)
        .background(isTinted ? Color(.systemBlue).opacity(0.14) : .clear, in: .rect(cornerRadius: 10))
        .accessibilityIdentifier(isTinted ? "step.current" : "tools.\(item.key)")
    }
}

/// One call. A call with output opens that output in a sheet when it is done.
private struct StepRow: View {
    let step: ToolStep
    let isCurrent: Bool
    let at: String?
    @State private var isShowingOutput = false
    @ScaledMetric(relativeTo: .subheadline) private var iconWidth = 18.0

    private var hasOutput: Bool { !step.isRunning && !(step.output ?? "").isEmpty }

    var body: some View {
        Group {
            if hasOutput {
                Button {
                    isShowingOutput = true
                } label: {
                    row
                }.buttonStyle(.plain)
                    .accessibilityHint("chat.step.showOutput")
            } else {
                row
            }
        }
        .sheet(isPresented: $isShowingOutput) { StepOutputSheet(step: step) }
        .accessibilityIdentifier(isCurrent ? "step.current" : "step.\(step.key)")
    }

    private var row: some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            if isCurrent {
                ProgressView().controlSize(.small).accessibilityHidden(true)
            } else {
                Image(systemName: Self.symbol(for: step.category)).font(.caption).foregroundStyle(Color.yakMuted)
                    .frame(width: iconWidth).accessibilityHidden(true)
            }
            VStack(alignment: .leading, spacing: 2) {
                StepWording.text(for: step).font(.subheadline)
                if step.isFailed {
                    Text("chat.step.failed").font(.caption.weight(.semibold)).foregroundStyle(Color.yakDanger)
                }
                if isCurrent, let start = RelativeAge.parse(at) { ElapsedView(since: start) }
            }
            .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, isCurrent ? 10 : 0).padding(.vertical, 6).frame(minHeight: 36)
        .background(isCurrent ? Color(.systemBlue).opacity(0.14) : .clear, in: .rect(cornerRadius: 10))
        .contentShape(.rect)
    }

    private static func symbol(for category: ToolStep.Category) -> String {
        switch category {
        case .command: "terminal"
        case .read: "doc.text"
        case .search: "magnifyingglass"
        case .fetch: "globe"
        }
    }
}

/// The words of a step: what it is doing while it runs, what it did once it has a result.
enum StepWording {
    private static let labelLimit = 300

    /// The label on one line, cut when it is very long.
    static func displayLabel(_ label: String) -> String {
        let oneLine = label.split(whereSeparator: \.isNewline).joined(separator: " ")
        return oneLine.count > labelLimit ? String(oneLine.prefix(labelLimit)) + "..." : oneLine
    }

    static func text(for step: ToolStep) -> Text {
        let label = displayLabel(step.label)
        switch (step.category, step.isRunning) {
        case (.command, true): return Text("chat.step.command.running \(label)")
        case (.command, false): return Text("chat.step.command.done \(label)")
        case (.read, true): return Text("chat.step.read.running \(label)")
        case (.read, false): return Text("chat.step.read.done \(label)")
        case (.search, true): return Text("chat.step.search.running \(label)")
        case (.search, false): return Text("chat.step.search.done \(label)")
        case (.fetch, true): return Text("chat.step.fetch.running \(label)")
        case (.fetch, false): return Text("chat.step.fetch.done \(label)")
        }
    }
}

/// The time since a call began, counting each second.
private struct ElapsedView: View {
    let since: Date

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { context in
            Text(verbatim: ElapsedText.make(seconds: context.date.timeIntervalSince(since)))
                .font(.subheadline).monospacedDigit().foregroundStyle(.primary)
        }
        .accessibilityHidden(true)
    }
}

/// The output of a finished call, monospaced and selectable. A very long output shows its start.
private struct StepOutputSheet: View {
    let step: ToolStep
    @Environment(\.dismiss) private var dismiss
    private static let limit = 20_000

    private var shown: String { String((step.output ?? "").prefix(Self.limit)) }
    private var isCut: Bool { (step.output ?? "").count > Self.limit }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    StepWording.text(for: step).font(.subheadline.weight(.semibold))
                    Text(verbatim: shown).font(.footnote.monospaced()).textSelection(.enabled)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    if isCut {
                        Text("chat.output.cut").font(.footnote).foregroundStyle(Color.yakMuted)
                    }
                }
                .padding(16)
            }
            .navigationTitle("chat.output.title").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("common.close") { dismiss() } } }
        }
        .presentationDetents([.medium, .large])
        .accessibilityIdentifier("chat.output")
    }
}
