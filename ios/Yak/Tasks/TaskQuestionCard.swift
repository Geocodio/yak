import SwiftUI
import YakKit

/// Yak's clarification questions on the task screen: one question at a time, then a review before sending. The
/// steps and the saved draft are YakKit's `QuestionFlow`; this view only draws them.
struct TaskQuestionCard: View {
    let model: TaskModel
    let flow: QuestionFlow
    @State private var typed = ""
    @FocusState private var isTyping: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("task.questions").font(.headline).accessibilityAddTraits(.isHeader)
            switch flow.step {
            case .question(let index): question(index)
            case .typing(let index): typing(index)
            case .review: review
            }
            if let message = model.message {
                Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("task.questions.error")
            }
        }
        .padding(16).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.primary.opacity(0.06), in: .rect(cornerRadius: 16))
        .accessibilityIdentifier("task.questions")
    }

    private func question(_ index: Int) -> some View {
        let item = flow.questions[index]
        return VStack(alignment: .leading, spacing: 10) {
            Text("task.questions.step \(index + 1) \(flow.questions.count)").font(.footnote.weight(.medium))
                .foregroundStyle(Color.yakMuted)
            Text(verbatim: item.question).font(.body).fixedSize(horizontal: false, vertical: true)
            ForEach(item.options, id: \.label) { option in
                Button {
                    model.changeQuestions { $0.pick(option.label) }
                } label: {
                    HStack(alignment: .firstTextBaseline, spacing: 10) {
                        Image(
                            systemName: flow.isSelected(option.label, question: index)
                                ? "checkmark.circle.fill" : "circle"
                        )
                        .accessibilityHidden(true)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: option.label).font(.body).multilineTextAlignment(.leading)
                            if !option.description.isEmpty {
                                Text(verbatim: option.description).font(.footnote.weight(.medium))
                                    .foregroundStyle(Color.yakMuted).multilineTextAlignment(.leading)
                            }
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(12).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .background(Color(.systemBackground), in: .rect(cornerRadius: 12))
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(flow.isSelected(option.label, question: index) ? .isSelected : [])
                .accessibilityIdentifier("task.questions.option")
            }
            Button {
                typed = flow.answers[item.id]?.other ?? ""
                model.changeQuestions { $0.startTyping() }
            } label: {
                Text("task.questions.other").frame(maxWidth: .infinity, minHeight: 44)
            }
            .accessibilityIdentifier("task.questions.other")
            HStack(spacing: 12) {
                if index > 0 {
                    Button("task.questions.back") { model.changeQuestions { $0.back() } }
                        .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.questions.back")
                }
                if item.multiSelect {
                    Button("task.questions.next") { model.changeQuestions { $0.next() } }
                        .buttonStyle(SecondaryButtonStyle()).disabled(!flow.canGoNext)
                        .accessibilityIdentifier("task.questions.next")
                }
            }
        }
    }

    private func typing(_ index: Int) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(verbatim: flow.questions[index].question).font(.body).fixedSize(horizontal: false, vertical: true)
            TextField("task.questions.typed", text: $typed, axis: .vertical).lineLimit(2...6)
                .focused($isTyping).padding(12).textFieldStyle(.plain)
                .background(Color(.systemBackground), in: .rect(cornerRadius: 12))
                .accessibilityIdentifier("task.questions.field")
            HStack(spacing: 12) {
                Button("common.cancel") { model.changeQuestions { $0.cancelTyping() } }
                    .buttonStyle(SecondaryButtonStyle())
                Button("task.questions.save") { model.changeQuestions { $0.submitTyped(typed) } }
                    .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("task.questions.save")
            }
        }
        .onAppear { isTyping = true }
    }

    private var review: some View {
        VStack(alignment: .leading, spacing: 10) {
            ForEach(Array(flow.questions.enumerated()), id: \.offset) { index, item in
                Button {
                    model.changeQuestions { $0.edit(index) }
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: item.header).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        Text(verbatim: flow.summary(for: item.id) ?? "").font(.body).multilineTextAlignment(.leading)
                    }
                    .padding(12).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .background(Color(.systemBackground), in: .rect(cornerRadius: 12))
                }
                .buttonStyle(.plain).accessibilityHint("task.questions.edit")
            }
            TextField("task.questions.note", text: noteBinding, axis: .vertical).lineLimit(1...4)
                .padding(12).background(Color(.systemBackground), in: .rect(cornerRadius: 12))
                .accessibilityIdentifier("task.questions.note")
            Button {
                Task { await model.sendAnswers() }
            } label: {
                if model.isSendingAnswers { ProgressView() } else { Text("task.questions.send") }
            }
            .buttonStyle(SecondaryButtonStyle()).disabled(!flow.canSend || model.isSendingAnswers || model.isOffline)
            .accessibilityIdentifier("task.questions.send")
        }
    }

    private var noteBinding: Binding<String> {
        Binding(get: { flow.note }, set: { text in model.changeQuestions { $0.setNote(text) } })
    }
}
