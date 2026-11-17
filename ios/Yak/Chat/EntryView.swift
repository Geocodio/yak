import SwiftUI
import YakKit

/// One row of the conversation: a day divider, an entry or the waiting row.
struct ChatRowView: View {
    let row: ConversationRow
    let thread: ThreadModel
    let openThread: (String) -> Void

    var body: some View {
        switch row {
        case .divider(let label, _): DayDivider(label: label)
        case .entry(let entry, let isCurrentStep):
            EntryView(entry: entry, isCurrentStep: isCurrentStep, thread: thread, openThread: openThread)
        case .waiting(let waiting): WaitingRowView(waiting: waiting)
        }
    }
}

private struct DayDivider: View {
    let label: DayLabel

    var body: some View {
        Group {
            switch label {
            case .today: Text("chat.day.today")
            case .yesterday: Text("chat.day.yesterday")
            case .date(let date): Text(date, format: .dateTime.year().month(.abbreviated).day())
            }
        }
        .font(.caption.weight(.semibold)).foregroundStyle(Color.yakMuted)
        .frame(maxWidth: .infinity).padding(.top, 6)
        .accessibilityAddTraits(.isHeader)
    }
}

private struct WaitingRowView: View {
    let waiting: WaitingRow

    var body: some View {
        HStack(spacing: 8) {
            ProgressView().controlSize(.small).accessibilityHidden(true)
            Text(waiting.isQueued ? "chat.waiting.queued" : "chat.waiting.active")
                .font(.subheadline).foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("chat.waiting")
    }
}

struct EntryView: View {
    let entry: ConversationEntry
    let isCurrentStep: Bool
    let thread: ThreadModel
    let openThread: (String) -> Void

    var body: some View {
        switch entry {
        case .person(let message, let isPending):
            PersonMessageView(message: message, isPending: isPending, thread: thread)
        case .event(let item):
            EventView(item: item, isCurrentStep: isCurrentStep, thread: thread, openThread: openThread)
        case .unsent(let item):
            UnsentView(item: item, isOffline: thread.isOffline, thread: thread)
        case .delivered(let delivery):
            DeliveredView(delivery: delivery)
        }
    }
}

// MARK: People

/// The bubble every message of a person sits in, on the trailing side.
struct MessageBubble<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        content
            .padding(.horizontal, 14).padding(.vertical, 10)
            .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 18))
            .frame(maxWidth: .infinity, alignment: .trailing)
    }
}

/// Messages longer than this show their start; a message is never this long in practice.
private let displayedTextLimit = 20_000

private struct PersonMessageView: View {
    let message: ThreadMessage
    let isPending: Bool
    let thread: ThreadModel
    @State private var editing: EditTarget?

    private struct EditTarget: Identifiable {
        let uuid: String
        let text: String
        var id: String { uuid }
    }

    private var otherName: String? {
        guard let name = message.authorName, !name.isEmpty, name != thread.ownName else { return nil }
        return name
    }

    private var isQueued: Bool { message.mode == .queue }

    var body: some View {
        VStack(alignment: .trailing, spacing: 4) {
            if let otherName {
                Text(otherName).font(.caption).foregroundStyle(Color.yakMuted)
            }
            MessageBubble {
                Text(verbatim: String(message.text.prefix(displayedTextLimit))).textSelection(.enabled)
            }
            .accessibilityLabel(
                otherName.map { Text("chat.message.from \($0) \(message.text)") } ?? Text(verbatim: message.text))
            attachments
            if isPending { pendingLine }
        }
        .frame(maxWidth: .infinity, alignment: .trailing)
        .sheet(item: $editing) { target in
            EditQueuedSheet(uuid: target.uuid, text: target.text, thread: thread)
        }
    }

    @ViewBuilder private var attachments: some View {
        if let list = message.attachments, !list.isEmpty {
            let rows = MessageAttachmentRows.make(list, instance: thread.instanceURL, canonical: nil).rows
            VStack(alignment: .trailing, spacing: 4) {
                ForEach(rows) { row in
                    AttachmentChip(title: row.title, detail: row.detail, isImage: row.kind == .image)
                }
            }
        }
    }

    private var pendingLine: some View {
        HStack(spacing: 0) {
            Text(isQueued ? "chat.message.sendsAfterTurn" : "chat.message.waitingToBeRead")
                .font(.caption).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
            if let uuid = message.uuid {
                Menu {
                    Button {
                        editing = EditTarget(uuid: uuid, text: message.text)
                    } label: {
                        Label("chat.message.edit", systemImage: "pencil")
                    }
                    if isQueued {
                        Button {
                            Task { await thread.setQueuedMode(uuid, mode: .steer) }
                        } label: {
                            Label("chat.message.sendNow", systemImage: "paperplane")
                        }
                    } else {
                        Button {
                            Task { await thread.setQueuedMode(uuid, mode: .queue) }
                        } label: {
                            Label("chat.message.sendAfterTurn", systemImage: "clock")
                        }
                    }
                    Button(role: .destructive) {
                        Task { await thread.withdrawQueued(uuid) }
                    } label: {
                        Label("chat.message.remove", systemImage: "trash")
                    }
                } label: {
                    Image(systemName: "ellipsis").foregroundStyle(.primary).frame(minWidth: 44, minHeight: 44)
                        .contentShape(.rect)
                }
                .tint(.primary)
                .accessibilityLabel("chat.message.menu")
                .accessibilityIdentifier("message.menu.\(uuid)")
            }
        }
    }
}

private struct DeliveredView: View {
    let delivery: OutboxDelivery

    var body: some View {
        VStack(alignment: .trailing, spacing: 4) {
            MessageBubble { Text(verbatim: String(delivery.text.prefix(displayedTextLimit))).textSelection(.enabled) }
            if delivery.status == .queued {
                Text("chat.message.sendsAfterTurn").font(.caption).foregroundStyle(Color.yakMuted)
            }
        }
        .frame(maxWidth: .infinity, alignment: .trailing)
    }
}

/// A file that came with a message: its name and size, as a quiet chip.
struct AttachmentChip: View {
    let title: String
    let detail: String
    let isImage: Bool

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: isImage ? "photo" : "doc").foregroundStyle(Color.yakMuted).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.footnote.weight(.medium))
                if !detail.isEmpty { Text(verbatim: detail).font(.caption2).foregroundStyle(Color.yakMuted) }
            }
        }
        .padding(.horizontal, 10).padding(.vertical, 6)
        .background(Color(.tertiarySystemFill), in: .rect(cornerRadius: 10))
        .accessibilityElement(children: .combine)
    }
}

/// The sheet that changes the text of a message that waits for the next turn.
private struct EditQueuedSheet: View {
    let uuid: String
    @State var text: String
    let thread: ThreadModel
    @State private var isSaving = false
    @State private var problem: String?
    @Environment(\.dismiss) private var dismiss

    init(uuid: String, text: String, thread: ThreadModel) {
        self.uuid = uuid
        _text = State(initialValue: text)
        self.thread = thread
    }

    private var canSave: Bool { !isSaving && !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 12) {
                TextEditor(text: $text)
                    .padding(8).background(Color(.secondarySystemFill), in: .rect(cornerRadius: 12))
                    .frame(minHeight: 120)
                    .accessibilityLabel("chat.message.editField")
                    .accessibilityIdentifier("message.edit.field")
                if let problem {
                    Text(problem).font(.subheadline).foregroundStyle(Color.yakDanger)
                        .fixedSize(horizontal: false, vertical: true)
                        .accessibilityIdentifier("message.edit.problem")
                }
                Spacer(minLength: 0)
            }
            .padding(16)
            .navigationTitle("chat.message.editTitle").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("common.cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button {
                        Task { await save() }
                    } label: {
                        if isSaving { ProgressView() } else { Text("chat.message.save") }
                    }
                    .disabled(!canSave).accessibilityIdentifier("message.edit.save")
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private func save() async {
        isSaving = true
        problem = nil
        let outcome = await thread.editQueued(uuid, text: text)
        isSaving = false
        switch outcome {
        case .done, .alreadySent: dismiss()
        case .refused(_, let message, _): problem = message.isEmpty ? String(localized: "chat.action.refused") : message
        case .failed(.offline): problem = String(localized: "chat.action.offline")
        case .failed: problem = String(localized: "chat.action.failed")
        }
    }
}

// MARK: Yak

/// What Yak wrote or did: text, thinking, tool activity, edits, a result or a card.
private struct EventView: View {
    let item: ConversationItem
    let isCurrentStep: Bool
    let thread: ThreadModel
    let openThread: (String) -> Void

    var body: some View {
        switch item.kind {
        case .text: AssistantText(item: item, thread: thread)
        case .thinking: ThinkingRow(item: item)
        case .tools: ToolActivityView(item: item, isCurrentStep: isCurrentStep)
        case .edits: EditsRow(item: item)
        case .result: ResultView(item: item, thread: thread)
        case .child:
            if let card = item.child { ChildCardView(card: card, openThread: openThread) }
        case .repository:
            if let card = item.repository { RepositoryCardView(card: card) }
        }
    }
}

/// Yak's text at full width, from the Markdown cache; a text that is not parsed yet shows as it is.
private struct AssistantText: View {
    let item: ConversationItem
    let thread: ThreadModel

    var body: some View {
        Group {
            if let document = thread.documents[item.key] {
                AppMarkdownRenderer().view(document)
            } else {
                Text(verbatim: String(item.text?.prefix(displayedTextLimit) ?? "")).textSelection(.enabled)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .accessibilityIdentifier("chat.text")
    }
}

private struct ThinkingRow: View {
    let item: ConversationItem
    @Environment(\.chatStartsExpanded) private var startsExpanded
    @State private var isExpanded: Bool?

    private var expanded: Bool { isExpanded ?? startsExpanded }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            DisclosureButton(isExpanded: expanded, toggle: { isExpanded = !expanded }) {
                Text("chat.thinking").font(.subheadline).foregroundStyle(Color.yakMuted)
            }
            .accessibilityIdentifier("thinking.\(item.key)")
            if expanded, let text = item.text {
                Text(verbatim: String(text.prefix(displayedTextLimit))).font(.subheadline).foregroundStyle(
                    Color.yakMuted
                )
                .textSelection(.enabled).frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }
}

private struct EditsRow: View {
    let item: ConversationItem
    @Environment(\.chatStartsExpanded) private var startsExpanded
    @State private var isExpanded: Bool?

    private var expanded: Bool { isExpanded ?? startsExpanded }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            DisclosureButton(isExpanded: expanded, toggle: { isExpanded = !expanded }) {
                InlineOrStacked {
                    Text("chat.edits.summary \(item.files.count)").font(.subheadline.weight(.medium))
                    Text("chat.edits.counts \(item.added) \(item.removed)")
                        .font(.subheadline).monospacedDigit().foregroundStyle(Color.yakMuted)
                }
            }
            .accessibilityIdentifier("edits.\(item.key)")
            if expanded {
                VStack(alignment: .leading, spacing: 2) {
                    ForEach(item.files, id: \.self) { path in
                        Text(verbatim: path).font(.footnote.monospaced()).foregroundStyle(Color.yakMuted)
                            .textSelection(.enabled)
                            .accessibilityLabel(Text("chat.edits.file \(path)"))
                    }
                }
                .padding(.leading, 22)
            }
        }
    }
}

/// Pieces of one line that sit side by side when they fit and stack when they do not, as at the largest text size.
struct InlineOrStacked<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 8) { content }
            VStack(alignment: .leading, spacing: 2) { content }
        }
    }
}

/// A row that opens and closes, with the chevron on its trailing side.
struct DisclosureButton<Label: View>: View {
    let isExpanded: Bool
    let toggle: () -> Void
    @ViewBuilder let label: Label

    var body: some View {
        Button(action: toggle) {
            HStack(spacing: 8) {
                label.fixedSize(horizontal: false, vertical: true)
                Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(Color.yakMuted)
                    .rotationEffect(.degrees(isExpanded ? 90 : 0)).accessibilityHidden(true)
                Spacer(minLength: 0)
            }
            .frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityValue(isExpanded ? Text("chat.expanded") : Text("chat.collapsed"))
    }
}

private struct ResultView: View {
    let item: ConversationItem
    let thread: ThreadModel

    var body: some View {
        if item.isError {
            VStack(alignment: .leading, spacing: 6) {
                Label("chat.result.failed", systemImage: "exclamationmark.triangle.fill")
                    .font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
                if let text = item.text, !text.isEmpty {
                    Text(verbatim: String(text.prefix(displayedTextLimit))).font(.subheadline).textSelection(.enabled)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading).padding(14)
            .background(Color.yakDanger.opacity(0.12), in: .rect(cornerRadius: 14))
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("chat.result.failed")
        } else {
            AssistantText(item: item, thread: thread)
        }
    }
}

// MARK: Environment

private struct ChatStartsExpandedKey: EnvironmentKey {
    static let defaultValue = false
}

extension EnvironmentValues {
    /// Folded rows (thinking, tool groups, edits) start open; the debug gallery sets it to show their content.
    var chatStartsExpanded: Bool {
        get { self[ChatStartsExpandedKey.self] }
        set { self[ChatStartsExpandedKey.self] = newValue }
    }
}
