import SwiftUI
import YakKit

/// The Chat pane's row in the bottom bar: the message field with plus, Send and Stop, or the reason a message
/// cannot be sent. The typed text stays in the model through every state.
struct ComposerBar: View {
    let thread: ThreadModel
    @Environment(SceneModel.self) private var scene
    @FocusState private var isFocused: Bool

    private var composer: ComposerModel { thread.composer }

    var body: some View {
        @Bindable var composer = composer
        VStack(alignment: .leading, spacing: 6) {
            if let problem = composer.problem {
                Text(problem).font(.footnote).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true).padding(.horizontal, 12)
                    .accessibilityIdentifier("composer.problem")
            }
            if let reason = composer.bar.unavailable {
                UnavailableRow(reason: reason, repo: thread.snapshot?.show?.thread.repo) {
                    scene.openNewThread(repo: thread.snapshot?.show?.thread.repo, text: nil)
                }
            } else {
                HStack(alignment: .bottom, spacing: 8) {
                    if composer.bar.showsPlus { plusButton }
                    field($composer.text)
                    if composer.bar.showsStop { stopButton }
                    if composer.bar.showsSend { sendControl }
                }
            }
        }
        .background { shortcuts }
        .sensoryFeedback(.error, trigger: composer.refusalCount)
        .animation(.smooth(duration: 0.2), value: composer.bar)
    }

    // MARK: Controls

    private func field(_ text: Binding<String>) -> some View {
        let placeholder: LocalizedStringKey =
            composer.bar.placeholder == .steerYak ? "composer.steer" : "composer.message"
        return TextField(placeholder, text: text, axis: .vertical)
            .lineLimit(1...6)
            .focused($isFocused)
            .textInputAutocapitalization(.sentences)
            .padding(.horizontal, 14).padding(.vertical, 11)
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .background(Color.primary.opacity(0.06), in: .rect(cornerRadius: 22))
            .accessibilityLabel("composer.field.label")
            .accessibilityIdentifier("composer.field")
    }

    /// The menu behind it arrives with the attachments and the model picker.
    private var plusButton: some View {
        Button {
        } label: {
            Image(systemName: "plus").font(.title3.weight(.medium)).frame(width: 44, height: 44).contentShape(.circle)
        }
        .tint(.primary).disabled(true)
        .accessibilityLabel("composer.plus.label")
        .accessibilityIdentifier("composer.plus")
    }

    /// A tap steers; a long press offers "Send after this turn" while a turn runs.
    private var sendControl: some View {
        Button {
            send(.steer)
        } label: {
            SendGlyph(isBusy: composer.isSending)
        }
        .buttonStyle(.plain).disabled(!composer.canSend)
        .contextMenu {
            if composer.bar.offersSendAfterTurn {
                Button("composer.sendAfterTurn") { send(.afterTurn) }
                    .accessibilityIdentifier("composer.sendAfterTurn")
            }
        }
        .accessibilityLabel("composer.send.label")
        .accessibilityHint(composer.bar.offersSendAfterTurn ? Text("composer.send.hint") : Text(verbatim: ""))
        .accessibilityAction(named: Text("composer.sendAfterTurn")) {
            if composer.bar.offersSendAfterTurn { send(.afterTurn) }
        }
        .accessibilityIdentifier("composer.send")
    }

    private var stopButton: some View {
        Button {
            Task { await composer.stop() }
        } label: {
            ZStack {
                Circle().fill(Color.primary)
                if composer.isStopping {
                    ProgressView().tint(Color(.systemBackground))
                } else {
                    Image(systemName: "stop.fill").font(.footnote.weight(.bold)).foregroundStyle(
                        Color(.systemBackground))
                }
            }
            .frame(width: 44, height: 44).contentShape(.circle)
        }
        .buttonStyle(.plain).disabled(composer.isStopping)
        .accessibilityLabel("composer.stop.label").accessibilityIdentifier("composer.stop")
    }

    private func send(_ delivery: MessageDelivery) {
        Task { await composer.send(delivery: delivery) }
    }

    /// Command Return sends, Command Shift Return sends after this turn, Command period stops.
    private var shortcuts: some View {
        Group {
            Button("composer.shortcut.send") { send(.steer) }.keyboardShortcut(.return, modifiers: .command)
            Button("composer.shortcut.sendAfterTurn") { send(.afterTurn) }.keyboardShortcut(
                .return, modifiers: [.command, .shift])
            Button("composer.shortcut.stop") { Task { await composer.stop() } }.keyboardShortcut(
                ".", modifiers: .command)
        }
        .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
    }
}

/// The arrow on a neutral fill; a spinner replaces it while the message is being saved.
private struct SendGlyph: View {
    let isBusy: Bool

    var body: some View {
        ZStack {
            Circle().fill(Color.primary)
            if isBusy {
                ProgressView().tint(Color(.systemBackground))
            } else {
                Image(systemName: "arrow.up").font(.body.weight(.bold)).foregroundStyle(Color(.systemBackground))
            }
        }
        .frame(width: 44, height: 44).contentShape(.circle)
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
            VStack(alignment: .leading, spacing: 2) {
                title.font(.subheadline.weight(.semibold))
                detail.font(.footnote).foregroundStyle(Color.primary.opacity(0.85))
            }
            .fixedSize(horizontal: false, vertical: true)
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
