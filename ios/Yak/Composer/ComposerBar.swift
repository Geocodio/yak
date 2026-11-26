import SwiftUI
import YakKit

/// The Chat pane's row in the bottom bar: the message field with plus, Send and Stop, or the reason a message
/// cannot be sent. The typed text stays in the model through every state.
struct ComposerBar: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @FocusState private var isFocused: Bool
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.openAddSubThread) private var openAddSubThread
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var isKeyboardUp = false

    /// A field of the conversation page has the keyboard: the composer shrinks to one line so the page keeps its room.
    private var isCollapsed: Bool { isKeyboardUp && !isFocused }

    private var composer: ComposerModel { thread.composer }

    private func takeFocusIfOpen() {
        guard !inputBlocked, scene.takeComposerFocus() else { return }
        isFocused = true
    }

    var body: some View {
        @Bindable var composer = composer
        VStack(alignment: .leading, spacing: 6) {
            if let problem = composer.problem {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Image(systemName: "exclamationmark.circle").foregroundStyle(Color.yakDanger)
                        .accessibilityHidden(true)
                    Text(problem).font(.yak(.footnote)).foregroundStyle(.primary)
                }
                .fixedSize(horizontal: false, vertical: true).padding(.horizontal, 12).padding(.vertical, 6)
                .background(Color.yakDanger.opacity(0.1), in: .rect(cornerRadius: 12))
                .accessibilityElement(children: .combine).accessibilityIdentifier("composer.problem")
            }
            AttachmentStrip(composer: composer)
            PasteImageButton(composer: composer)
            if let reason = composer.bar.unavailable {
                UnavailableRow(reason: reason, repo: thread.snapshot?.show?.thread.shownRepo) {
                    scene.openNewThread(repo: thread.snapshot?.show?.thread.shownRepo, text: nil)
                }
            } else {
                HStack(alignment: .bottom, spacing: 8) {
                    if composer.bar.showsPlus {
                        PlusMenu(
                            composer: composer,
                            onAddSubThread: thread.snapshot?.show?.canDrive == true ? openAddSubThread : nil)
                    }
                    field($composer.text)
                    if composer.bar.showsStop { stopButton }
                    if composer.bar.showsSend { sendControl }
                }
            }
        }
        .preference(key: ComposerFocusKey.self, value: isFocused)
        .background { shortcuts }
        .composerDropTarget(composer)
        #if DEBUG
            .task { await composer.addTestFixtures(count: TestMode.attachmentFixtureCount()) }
        #endif
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in
            // After this turn, so a tap on the composer has already given it focus.
            Task { @MainActor in isKeyboardUp = true }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in
            isKeyboardUp = false
        }
        .onChange(of: scene.composerFocusRequest) { takeFocusIfOpen() }
        .onChange(of: inputBlocked) { takeFocusIfOpen() }
        .task { takeFocusIfOpen() }
        .sensoryFeedback(.error, trigger: composer.refusalCount)
        .haptic(.sent, trigger: composer.acceptedSendCount)
        .motion(Motion.content, value: composer.bar)
        .motion(Motion.content, value: composer.attachments.count)
        .motion(Motion.content, value: isCollapsed)
    }

    // MARK: Controls

    private func field(_ text: Binding<String>) -> some View {
        let isSteering = composer.bar.placeholder == .steerYak
        let isRetrying = composer.bar.placeholder == .replyToTryAgain
        let isContinuing = composer.bar.line != nil
        // The short words fit the field at the largest text sizes, where the full ones are cut.
        let placeholder: LocalizedStringKey =
            dynamicTypeSize.isAccessibilitySize
            ? (isSteering ? "composer.steer.short" : (isRetrying ? "composer.retry.short" : "composer.message.short"))
            : (isSteering
                ? "composer.steer"
                : (isRetrying ? "composer.retry" : (isContinuing ? "composer.continue" : "composer.message")))
        // A vertical text field keeps the height of the largest text size it has seen, so each text size gets a new one.
        return TextField(text: text, prompt: Text(placeholder).foregroundStyle(Color.yakMuted), axis: .vertical) {
            Text(placeholder)
        }
        .id(dynamicTypeSize)
        .font(.yakField)
        .lineLimit(isCollapsed ? 1...1 : 1...6)
        .focused($isFocused)
        .textInputAutocapitalization(.sentences)
        .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.snug)
        .frame(maxWidth: .infinity, minHeight: Design.Size.control, alignment: .leading)
        .accessibilityLabel("composer.field.label")
        .accessibilityIdentifier("composer.field")
    }

    /// A tap steers; a long press offers "Send after this turn" while a turn runs.
    private var sendControl: some View {
        Button {
            send(.steer)
        } label: {
            SendGlyph(isBusy: composer.isSending)
        }
        .buttonStyle(.pressable).disabled(!composer.canSend).hoverEffect(.highlight)
        .contextMenu {
            if composer.bar.offersSendAfterTurn {
                Button("composer.sendAfterTurn") { send(.afterTurn) }
                    .accessibilityIdentifier("composer.sendAfterTurn")
            }
        }
        .accessibilityLabel("composer.send.label")
        .accessibilityHint(
            composer.preparingCount > 0
                ? Text("composer.send.preparing")
                : composer.bar.offersSendAfterTurn ? Text("composer.send.hint") : Text(verbatim: "")
        )
        .accessibilityAction(named: Text("composer.sendAfterTurn")) {
            if composer.bar.offersSendAfterTurn { send(.afterTurn) }
        }
        .popoverTip(SendAfterTurnTip(), arrowEdge: .bottom)
        .onChange(of: tipIsEligible, initial: true) { SendAfterTurnTip.isEligible = tipIsEligible }
        .accessibilityIdentifier("composer.send")
    }

    private var tipIsEligible: Bool {
        GestureTips.eligibleForSendAfterTurn(
            offersSendAfterTurn: composer.bar.offersSendAfterTurn, hasText: !composer.text.isEmpty)
    }

    private var stopButton: some View {
        Button {
            Task { await composer.stop() }
        } label: {
            ZStack {
                RoundedRectangle(cornerRadius: Design.Radius.control).fill(Color.yakBubble)
                    .overlay(
                        RoundedRectangle(cornerRadius: Design.Radius.control)
                            .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line))
                if composer.isStopping {
                    ProgressView().tint(Color.yakText)
                } else {
                    Image(systemName: "stop.fill").font(.system(size: 15, weight: .bold))
                        .foregroundStyle(Color.yakText)
                }
            }
            .frame(width: 44, height: 44).contentShape(.rect(cornerRadius: Design.Radius.control))
        }
        .buttonStyle(.pressable).disabled(composer.isStopping)
        .accessibilityLabel("composer.stop.label").accessibilityIdentifier("composer.stop")
    }

    private func send(_ delivery: MessageDelivery) {
        if delivery == .afterTurn { SendAfterTurnTip.recordUse() }
        thread.sendStarted()
        Task { await composer.send(delivery: delivery) }
    }

    /// Command Return sends, Command Shift Return sends after this turn, Command period stops.
    private var shortcuts: some View {
        Group {
            Button("composer.shortcut.send") { send(.steer) }.yakShortcut(.send)
            Button("composer.shortcut.sendAfterTurn") { send(.afterTurn) }.yakShortcut(.sendAfterTurn)
            Button("composer.shortcut.stop") { Task { await composer.stop() } }.yakShortcut(.stop)
        }
        .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
    }
}

/// The arrow on a neutral fill; a spinner replaces it while the message is being saved.
private struct SendGlyph: View {
    let isBusy: Bool

    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: Design.Radius.control).fill(Color.primary)
            if isBusy {
                ProgressView().tint(Color.yakBackground)
            } else {
                Image(systemName: "arrow.up").font(.system(size: 18, weight: .bold)).foregroundStyle(
                    Color.yakBackground)
            }
        }
        .frame(width: 44, height: 44).contentShape(.rect(cornerRadius: Design.Radius.control))
    }
}

/// The reason in place of the field, and the one action that belongs to it.
private struct UnavailableRow: View {
    let reason: ComposerUnavailable
    let repo: String?
    let startNewThread: () -> Void
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 10))
            : AnyLayout(HStackLayout(alignment: .center, spacing: 12))
        layout {
            if reason == .closed {
                // One sentence group, as the board draws it: the title in bold, then what can still be done.
                HStack(alignment: .firstTextBaseline, spacing: 8) {
                    Image(systemName: "xmark.circle").foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                    Text(
                        "composer.closed.sentence \(title.font(.yak(.footnote, .semibold))) \(detail.font(.yak(.footnote)))"
                    )
                    .foregroundStyle(Color.primary.opacity(0.85))
                }
                .fixedSize(horizontal: false, vertical: true)
            } else {
                VStack(alignment: .leading, spacing: 2) {
                    title.font(.yak(.subheadline, .semibold))
                    detail.font(.yak(.footnote)).foregroundStyle(Color.primary.opacity(0.85))
                }
                .fixedSize(horizontal: false, vertical: true)
            }
            if dynamicTypeSize.isAccessibilitySize == false { Spacer(minLength: 0) }
            if reason == .closed {
                Button("composer.new", action: startNewThread)
                    .buttonStyle(.bordered).tint(.primary).controlSize(.large).fixedSize()
                    .accessibilityIdentifier("composer.new")
            }
        }
        .padding(.horizontal, 12).frame(minHeight: 44, alignment: .leading)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("composer.unavailable")
    }

    private var title: Text {
        switch reason {
        case .readOnly: Text("composer.readOnly.title")
        case .closed: Text("composer.closed.title")
        case .onComputer: Text("composer.computer.title")
        case .blocked(let title, _): title.map { Text(verbatim: $0) } ?? Text("composer.blocked.title")
        }
    }

    private var detail: Text {
        switch reason {
        case .readOnly: Text("composer.readOnly.detail")
        case .closed: Text("composer.closed.detail")
        case .onComputer: Text("composer.computer.detail")
        case .blocked(_, let body): body.map { Text(verbatim: $0) } ?? Text("composer.blocked.detail")
        }
    }
}
