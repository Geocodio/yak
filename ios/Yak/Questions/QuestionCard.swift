import SwiftUI
import UIKit
import YakKit

/// Yak's question in a thread: one question at a time, a review, and the send. It sits above the bar in Chat. The
/// steps, the answers and the saved draft are `QuestionModel` and YakKit's `QuestionFlow`; this view draws them.
struct QuestionCard: View {
    let model: QuestionModel
    /// A person who can only read the thread sees the questions and no controls.
    private var canAnswer: Bool { model.canAnswer }
    let isOffline: Bool
    /// The tallest the card gets; longer content scrolls inside it.
    let paneHeight: CGFloat

    @State private var scrollContentHeight: CGFloat = 0
    @State private var actionsHeight: CGFloat = 0
    @State private var position = ScrollPosition(edge: .top)
    @FocusState private var isTyping: Bool
    @FocusState private var isNoteFocused: Bool
    @AccessibilityFocusState private var focusedStep: Int?
    /// A vertical text field keeps the height of the largest text size it has seen, so each text size gets a new one.
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    /// A question step is at most 70 percent of the pane, the review 60, and while the person types it may use nearly all of it, because
    /// the card is the input then. It never gets less than room for the header, one option and the action row.
    private func cap(_ flow: QuestionFlow) -> CGFloat {
        if case .typing = flow.step { return max(paneHeight - 16, 260) }
        if case .question = flow.step { return max(paneHeight * 0.7, 260) }
        return max(paneHeight * 0.6, 260)
    }

    /// What the scrolling part may use: the cap minus the padding, the gap and the pinned actions.
    private func scrollHeight(_ flow: QuestionFlow) -> CGFloat {
        let available = max(cap(flow) - actionsHeight - 16 * 2 - 8, 150)
        return scrollContentHeight > 0 ? min(scrollContentHeight, available) : available
    }

    var body: some View {
        if let flow = model.flow {
            VStack(alignment: .leading, spacing: 0) {
                ScrollView {
                    scrolling(flow)
                        .onGeometryChange(for: CGFloat.self) {
                            $0.size.height
                        } action: {
                            scrollContentHeight = $0
                        }
                }
                .scrollPosition($position)
                .scrollBounceBehavior(.basedOnSize)
                .scrollDismissesKeyboard(.interactively)
                .frame(height: scrollHeight(flow))
                actions(flow)
                    .onGeometryChange(for: CGFloat.self) {
                        $0.size.height
                    } action: {
                        actionsHeight = $0
                    }
            }
            .questionCardSurface()
            .padding(.horizontal, 8).padding(.bottom, 8)
            .layoutPriority(1)
            .transition(.move(edge: .bottom).combined(with: .opacity))
            .accessibilityElement(children: .contain)
            .accessibilityLabel(Text("question.card.label"))
            .accessibilityIdentifier("question.card")
            // Every step starts at its top.
            .onChange(of: stepKey(flow)) {
                position.scrollTo(edge: .top)
                focusedStep = stepKey(flow)
            }
            .onChange(of: isTyping || isNoteFocused) { model.isEditing = isTyping || isNoteFocused }
            .onDisappear { model.isEditing = false }
            .onChange(of: model.isClosed) {
                if model.isClosed {
                    UIAccessibility.post(notification: .announcement, argument: String(localized: "question.closed"))
                }
            }
        }
    }

    /// The part that scrolls: the header, the question and its options, the review rows.
    @ViewBuilder private func scrolling(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            header(flow)
            if model.isClosed {
                closed(flow)
            } else if !canAnswer {
                readOnly(flow)
            } else {
                switch flow.step {
                case .question(let index): question(flow, index)
                case .typing(let index): questionText(flow.questions[index])
                case .review: review(flow)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    /// The part pinned at the bottom of the card, outside the scroll: the buttons and the field being typed in.
    @ViewBuilder private func actions(_ flow: QuestionFlow) -> some View {
        if model.isClosed {
            Button("question.dismiss") { model.dismiss() }
                .buttonStyle(SecondaryButtonStyle()).padding(.top, 12).accessibilityIdentifier("question.dismiss")
        } else if canAnswer {
            switch flow.step {
            case .question(let index):
                navigation(flow, index: index, isMultiSelect: flow.questions[index].multiSelect)
            case .typing: typingActions
            case .review: reviewActions(flow)
            }
        }
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

    /// Names the step so VoiceOver can move to its heading when the step changes.
    private func stepKey(_ flow: QuestionFlow) -> Int {
        switch flow.step {
        case .question(let index): index
        case .typing(let index): 1000 + index
        case .review: -1
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
            questionText(item).accessibilityFocused($focusedStep, equals: index)
            if item.multiSelect {
                Text("question.multiHint").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            ForEach(Array(item.options.enumerated()), id: \.offset) { optionIndex, option in
                QuestionOptionButton(
                    label: option.label, detail: option.description,
                    isSelected: flow.isSelected(option.label, question: index)
                ) {
                    Task { await model.pick(option.label, question: index) }
                }
                .accessibilityIdentifier("question.option.\(optionIndex)")
            }
            if !other.isEmpty {
                QuestionOptionButton(label: other, detail: nil, isSelected: true) { startTyping() }
                    .accessibilityIdentifier("question.other")
            }
            Button("question.typeAnswer") { startTyping() }
                .buttonStyle(SecondaryButtonStyle())
                .accessibilityIdentifier("question.typeAnswer")
            if let message = model.fieldErrors[item.id] { errorLine(message) }
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
                    Button("question.next") { Task { await model.next(question: index) } }
                        .buttonStyle(SecondaryButtonStyle()).disabled(!flow.canGoNext)
                        .accessibilityIdentifier("question.next")
                }
            }
            .padding(.top, 12)
        }
    }

    private func startTyping() {
        model.startTyping()
        isTyping = true
    }

    // MARK: Typing

    private var typingActions: some View {
        @Bindable var model = model
        return VStack(alignment: .leading, spacing: 10) {
            TextField("question.field", text: $model.typedText, axis: .vertical).lineLimit(1...4)
                .focused($isTyping).id(dynamicTypeSize).padding(12).textFieldStyle(.plain).questionFieldSurface()
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
        .padding(.top, 12)
        .onAppear { isTyping = true }
    }

    // MARK: Review

    private func review(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("question.review.title").font(.subheadline.weight(.semibold))
                .accessibilityFocused($focusedStep, equals: -1)
            ForEach(Array(flow.questions.enumerated()), id: \.offset) { index, item in
                QuestionReviewRow(
                    header: item.header, summary: flow.summary(for: item.id) ?? "", error: model.fieldErrors[item.id]
                ) {
                    Task { await model.edit(index) }
                }
                .disabled(model.isSending)
            }
            TextField("question.note", text: noteBinding, axis: .vertical).lineLimit(1...4)
                .focused($isNoteFocused).id(dynamicTypeSize)
                .padding(12).questionFieldSurface().disabled(model.isSending)
                .accessibilityIdentifier("question.note")
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("question.review")
    }

    private func reviewActions(_ flow: QuestionFlow) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if isOffline, model.sending == .idle {
                Text("question.offline").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("question.offline")
            }
            if model.isSlow {
                Text("question.slow").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("question.slow")
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
        .padding(.top, 12)
    }

    private var noteBinding: Binding<String> {
        Binding(
            get: { model.flow?.note ?? "" },
            set: { model.setNote($0) })
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
