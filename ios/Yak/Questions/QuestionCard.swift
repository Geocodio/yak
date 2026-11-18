import SwiftUI
import UIKit
import YakKit

/// Yak's question in a thread: one question at a time, a review, and the send. It sits above the bar in Chat. The
/// steps, the answers and the saved draft are `QuestionModel` and YakKit's `QuestionFlow`; this view draws them.
struct QuestionCard: View {
    let model: QuestionModel
    /// A person who can only read the thread sees the questions and no controls.
    let canAnswer: Bool
    let isOffline: Bool
    /// The tallest the card gets; longer content scrolls inside it.
    let maximumHeight: CGFloat

    @State private var contentHeight: CGFloat = 0
    @FocusState private var isTyping: Bool

    var body: some View {
        if let flow = model.flow {
            ScrollView {
                content(flow)
                    .onGeometryChange(for: CGFloat.self) {
                        $0.size.height
                    } action: {
                        contentHeight = $0
                    }
            }
            .scrollBounceBehavior(.basedOnSize)
            .scrollDismissesKeyboard(.interactively)
            .frame(maxHeight: contentHeight > 0 ? min(contentHeight, maximumHeight) : maximumHeight)
            .padding(.horizontal, 8).padding(.bottom, 4)
            .transition(.move(edge: .bottom).combined(with: .opacity))
            .accessibilityElement(children: .contain)
            .accessibilityLabel(Text("question.card.label"))
            .accessibilityIdentifier("question.card")
            .onChange(of: model.isClosed) {
                if model.isClosed {
                    UIAccessibility.post(notification: .announcement, argument: String(localized: "question.closed"))
                }
            }
        }
    }

    private func content(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            header(flow)
            if model.isClosed {
                closed(flow)
            } else if !canAnswer {
                readOnly(flow)
            } else {
                switch flow.step {
                case .question(let index): question(flow, index)
                case .typing(let index): typing(flow, index)
                case .review: review(flow)
                }
            }
        }
        .questionCardSurface()
    }

    private func header(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("question.title").font(.headline).accessibilityAddTraits(.isHeader)
            if canAnswer, !model.isClosed, let index = stepIndex(flow) {
                Text("question.progress \(index + 1) \(flow.questions.count)")
                    .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .accessibilityIdentifier("question.progress")
            }
        }
    }

    private func stepIndex(_ flow: QuestionFlow) -> Int? {
        switch flow.step {
        case .question(let index), .typing(let index): index
        case .review: nil
        }
    }

    // MARK: A question

    private func question(_ flow: QuestionFlow, _ index: Int) -> some View {
        let item = flow.questions[index]
        let other = flow.answers[item.id]?.other ?? ""
        return VStack(alignment: .leading, spacing: 10) {
            questionText(item)
            if item.multiSelect {
                Text("question.multiHint").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            ForEach(Array(item.options.enumerated()), id: \.offset) { optionIndex, option in
                QuestionOptionButton(
                    label: option.label, detail: option.description,
                    isSelected: flow.isSelected(option.label, question: index)
                ) {
                    Task { await model.pick(option.label) }
                }
                .accessibilityIdentifier("question.option.\(optionIndex)")
            }
            if !other.isEmpty {
                QuestionOptionButton(label: other, detail: nil, isSelected: true) { startTyping() }
                    .accessibilityIdentifier("question.other")
            }
            Button {
                startTyping()
            } label: {
                Text("question.typeAnswer").font(.body.weight(.medium)).frame(maxWidth: .infinity, minHeight: 44)
            }
            .foregroundStyle(.primary)
            .accessibilityIdentifier("question.typeAnswer")
            if let message = model.fieldErrors[item.id] { errorLine(message) }
            navigation(flow, index: index, isMultiSelect: item.multiSelect)
        }
    }

    private func questionText(_ item: ClarificationQuestion) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: item.header).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            Text(verbatim: item.question).font(.body).fixedSize(horizontal: false, vertical: true)
        }
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder private func navigation(_ flow: QuestionFlow, index: Int, isMultiSelect: Bool) -> some View {
        if index > 0 || isMultiSelect {
            HStack(spacing: 12) {
                if index > 0 {
                    Button("question.back") { Task { await model.back() } }
                        .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("question.back")
                }
                if isMultiSelect {
                    Button("question.next") { Task { await model.next() } }
                        .buttonStyle(SecondaryButtonStyle()).disabled(!flow.canGoNext)
                        .accessibilityIdentifier("question.next")
                }
            }
        }
    }

    private func startTyping() {
        model.startTyping()
        isTyping = true
    }

    // MARK: Typing

    private func typing(_ flow: QuestionFlow, _ index: Int) -> some View {
        @Bindable var model = model
        return VStack(alignment: .leading, spacing: 10) {
            questionText(flow.questions[index])
            TextField("question.field", text: $model.typedText, axis: .vertical).lineLimit(2...6)
                .focused($isTyping).padding(12).textFieldStyle(.plain).questionFieldSurface()
                .accessibilityIdentifier("question.field")
            HStack(spacing: 12) {
                Button("common.cancel") {
                    isTyping = false
                    model.cancelTyping()
                }
                .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("question.cancelTyping")
                Button("common.done") {
                    isTyping = false
                    Task { await model.submitTyped(model.typedText) }
                }
                .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("question.done")
            }
        }
        .onAppear { isTyping = true }
    }

    // MARK: Review

    private func review(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("question.review.title").font(.subheadline.weight(.semibold))
            ForEach(Array(flow.questions.enumerated()), id: \.offset) { index, item in
                QuestionReviewRow(
                    header: item.header, summary: flow.summary(for: item.id) ?? "", error: model.fieldErrors[item.id]
                ) {
                    Task { await model.edit(index) }
                }
                .disabled(model.isSending)
            }
            TextField("question.note", text: noteBinding, axis: .vertical).lineLimit(1...4)
                .padding(12).questionFieldSurface().disabled(model.isSending)
                .accessibilityIdentifier("question.note")
            if isOffline, model.sending == .idle {
                Text("question.offline").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("question.offline")
            }
            if case .failed(let message) = model.sending { errorLine(message) }
            Button {
                Task { await model.send() }
            } label: {
                if model.isSending {
                    HStack(spacing: 8) {
                        ProgressView()
                        Text("question.sending")
                    }
                } else if case .failed = model.sending {
                    Text("question.retry")
                } else {
                    Text("question.send")
                }
            }
            .buttonStyle(SecondaryButtonStyle()).disabled(!flow.canSend || model.isSending)
            .accessibilityIdentifier("question.send")
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("question.review")
    }

    private var noteBinding: Binding<String> {
        Binding(
            get: { model.flow?.note ?? "" },
            set: { text in Task { await model.setNote(text) } })
    }

    private func errorLine(_ message: String) -> some View {
        Text(verbatim: message).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
            .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("question.error")
    }

    // MARK: Closed and read-only

    /// The round closed while the card held answers: they stay in view until the person dismisses the card.
    private func closed(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("question.closed").font(.body.weight(.medium)).fixedSize(horizontal: false, vertical: true)
                .accessibilityIdentifier("question.closed")
            Text("question.closed.detail").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            ForEach(Array(flow.questions.enumerated()), id: \.offset) { _, item in
                if let summary = flow.summary(for: item.id) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: item.header).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                        Text(verbatim: summary).font(.body)
                    }
                    .padding(12).frame(maxWidth: .infinity, alignment: .leading).questionFieldSurface()
                    .accessibilityElement(children: .combine)
                }
            }
            let unsent = model.typedText.trimmingCharacters(in: .whitespacesAndNewlines)
            if !unsent.isEmpty {
                Text(verbatim: unsent).font(.body).padding(12).frame(maxWidth: .infinity, alignment: .leading)
                    .questionFieldSurface()
            }
            Button("question.dismiss") { model.dismiss() }
                .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("question.dismiss")
        }
    }

    private func readOnly(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            ForEach(Array(flow.questions.enumerated()), id: \.offset) { _, item in questionText(item) }
            Text("question.readOnly").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("question.readOnly")
        }
    }
}
