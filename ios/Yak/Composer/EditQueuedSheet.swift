import SwiftUI
import YakKit

/// The sheet that changes the text of a message that waits for the next turn.
struct EditQueuedSheet: View {
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
                    .padding(8).background(Color.yakBubble, in: .rect(cornerRadius: 12))
                    .frame(minHeight: 120)
                    .accessibilityLabel("chat.message.editField")
                    .accessibilityIdentifier("message.edit.field")
                if let problem {
                    Text(problem).font(.yak(.subheadline)).foregroundStyle(Color.yakDanger)
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
        case .refused(_, let message, _, _):
            problem = message.isEmpty ? String(localized: "chat.action.refused") : message
        case .failed(.offline): problem = String(localized: "chat.action.offline")
        case .failed: problem = String(localized: "chat.action.failed")
        }
    }
}
