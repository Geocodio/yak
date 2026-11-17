import SwiftUI

/// The feedback a co-owner sends with "Request changes". The text stays when the server refuses it.
struct RequestChangesSheet: View {
    static let limit = 5000

    let model: TaskModel
    let close: () -> Void
    @FocusState private var isFocused: Bool

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    Text("task.requestChanges.prompt").font(.subheadline.weight(.medium))
                        .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
                    TextEditor(text: Bindable(model).feedback)
                        .focused($isFocused).frame(minHeight: 160)
                        .padding(8).scrollContentBackground(.hidden)
                        .background(Color(.secondarySystemBackground), in: .rect(cornerRadius: 12))
                        .accessibilityLabel("task.requestChanges.field")
                        .accessibilityIdentifier("task.requestChanges.field")
                    HStack(alignment: .top) {
                        if let error = model.feedbackError {
                            Text(verbatim: error).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                                .fixedSize(horizontal: false, vertical: true)
                                .accessibilityIdentifier("task.requestChanges.error")
                        }
                        Spacer(minLength: 8)
                        Text("task.requestChanges.count \(model.feedback.count) \(Self.limit)")
                            .font(.footnote.weight(.medium))
                            .foregroundStyle(isTooLong ? Color.yakDanger : Color.yakMuted)
                    }
                    if let message = model.message {
                        Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                    }
                    Button {
                        Task {
                            if await model.perform(.requestChanges(feedback: model.feedback)) { close() }
                        }
                    } label: {
                        if model.isSending { ProgressView() } else { Text("task.requestChanges.send") }
                    }
                    .buttonStyle(PrimaryButtonStyle()).padding(.top, 8)
                    .disabled(isEmpty || isTooLong || model.isSending)
                    .accessibilityIdentifier("task.requestChanges.send")
                }
                .padding(20)
            }
            .scrollDismissesKeyboard(.interactively)
            .navigationTitle("task.requestChanges.title")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("common.cancel", action: close).accessibilityIdentifier("task.requestChanges.cancel")
                }
            }
        }
        .onAppear { isFocused = true }
    }

    private var isEmpty: Bool { model.feedback.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    private var isTooLong: Bool { model.feedback.count > Self.limit }
}
