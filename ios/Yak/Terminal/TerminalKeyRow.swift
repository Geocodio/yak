@preconcurrency import GameController
import SwiftUI
import YakKit

/// Whether a hardware keyboard is attached, so the row can shrink to the shell counter.
@MainActor @Observable
final class HardwareKeyboardMonitor {
    private(set) var isAttached = GCKeyboard.coalesced != nil
    @ObservationIgnored nonisolated(unsafe) private var tokens: [any NSObjectProtocol] = []

    init() {
        for name in [NSNotification.Name.GCKeyboardDidConnect, .GCKeyboardDidDisconnect] {
            tokens.append(
                NotificationCenter.default.addObserver(forName: name, object: nil, queue: .main) { [weak self] _ in
                    MainActor.assumeIsolated { self?.isAttached = GCKeyboard.coalesced != nil }
                })
        }
    }

    deinit { for token in tokens { NotificationCenter.default.removeObserver(token) } }
}

/// The keys of the bar: the shell counter, esc, tab, ctrl, the arrows and the keyboard key. Keys repeat while held.
struct TerminalKeyRow: View {
    let model: TerminalPaneModel
    let keyboard: HardwareKeyboardMonitor
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var showsArrowPad = false

    private var isConnected: Bool { model.focusedSnapshot?.phase == .connected }
    private var usesIcons: Bool { dynamicTypeSize.isAccessibilitySize }

    var body: some View {
        Group {
            if keyboard.isAttached && model.isSurfaceFocused && !model.isKeyboardShown {
                HStack {
                    ShellMenu(model: model)
                    Spacer(minLength: 0)
                }
            } else {
                ViewThatFits(in: .horizontal) {
                    row(withSideArrows: true)
                    row(withSideArrows: false)
                    ScrollView(.horizontal) { row(withSideArrows: false) }.scrollIndicators(.hidden)
                }
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("terminal.keys")
    }

    private func row(withSideArrows: Bool) -> some View {
        HStack(spacing: Design.Space.tight) {
            ShellMenu(model: model)
            key("terminal.key.escape", text: "esc", symbol: "escape", label: "terminal.key.escape.label", .escape)
            key("terminal.key.tab", text: "tab", symbol: "arrow.right.to.line", label: "terminal.key.tab.label", .tab)
            controlKey
            if withSideArrows {
                key("terminal.key.left", text: nil, symbol: "arrow.left", label: "terminal.key.left.label", .left)
            }
            key("terminal.key.up", text: nil, symbol: "arrow.up", label: "terminal.key.up.label", .up)
                .popover(isPresented: $showsArrowPad) { arrowPad }
                .simultaneousGesture(
                    LongPressGesture(minimumDuration: 0.6).onEnded { _ in if !withSideArrows { showsArrowPad = true } })
            key("terminal.key.down", text: nil, symbol: "arrow.down", label: "terminal.key.down.label", .down)
            if withSideArrows {
                key("terminal.key.right", text: nil, symbol: "arrow.right", label: "terminal.key.right.label", .right)
            }
            Button {
                model.toggleKeyboard()
            } label: {
                Image(systemName: model.isKeyboardShown ? "keyboard.chevron.compact.down" : "keyboard")
                    .frame(maxWidth: .infinity, minHeight: Design.Size.key)
                    .background(Color.yakFill, in: .rect(cornerRadius: Design.Radius.key)).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(model.isKeyboardShown ? "terminal.key.keyboard.hide" : "terminal.key.keyboard.show")
            .accessibilityIdentifier("terminal.key.keyboard")
        }
    }

    private var controlKey: some View {
        Button {
            model.control.toggle()
        } label: {
            keyLabel(text: "ctrl", symbol: "control")
                .overlay {
                    if model.control.isOn {
                        RoundedRectangle(cornerRadius: Design.Radius.key).fill(Color.primary.opacity(0.28))
                    }
                }
        }
        .buttonStyle(.plain)
        .accessibilityLabel("terminal.key.control.label")
        .accessibilityValue(model.control.isOn ? Text("terminal.key.on") : Text("terminal.key.off"))
        .accessibilityIdentifier("terminal.key.control")
        .disabled(!isConnected)
        .opacity(isConnected ? 1 : 0.45)
    }

    private func keyLabel(text: String?, symbol: String) -> some View {
        Group {
            if let text, !usesIcons {
                Text(verbatim: text).font(.yakChip).lineLimit(1).minimumScaleFactor(0.7)
            } else {
                Image(systemName: symbol)
            }
        }
        .frame(maxWidth: .infinity, minHeight: Design.Size.key)
        .background(Color.yakFill, in: .rect(cornerRadius: Design.Radius.key)).contentShape(.rect)
        .dynamicTypeSize(..<DynamicTypeSize.accessibility1)
    }

    private func key(_ identifier: String, text: String?, symbol: String, label: LocalizedStringKey, _ key: TerminalKey)
        -> some View
    {
        RepeatingKey(isEnabled: isConnected) {
            model.press(key)
        } label: {
            keyLabel(text: text, symbol: symbol)
        }
        .accessibilityLabel(label)
        .accessibilityIdentifier(identifier)
    }

    private var arrowPad: some View {
        Grid(horizontalSpacing: 4, verticalSpacing: 4) {
            GridRow {
                Color.clear.frame(width: 44, height: 44)
                key("terminal.key.pad.up", text: nil, symbol: "arrow.up", label: "terminal.key.up.label", .up)
                Color.clear.frame(width: 44, height: 44)
            }
            GridRow {
                key("terminal.key.pad.left", text: nil, symbol: "arrow.left", label: "terminal.key.left.label", .left)
                key("terminal.key.pad.down", text: nil, symbol: "arrow.down", label: "terminal.key.down.label", .down)
                key(
                    "terminal.key.pad.right", text: nil, symbol: "arrow.right", label: "terminal.key.right.label",
                    .right)
            }
        }
        .padding(8).presentationCompactAdaptation(.popover)
    }
}

/// A key that presses once at the touch and repeats after 0.4 seconds, every 0.08 seconds, while it is held.
private struct RepeatingKey<Label: View>: View {
    let isEnabled: Bool
    let press: () -> Void
    @ViewBuilder let label: Label
    @State private var repeater: Task<Void, Never>?
    @State private var isHeld = false

    var body: some View {
        label
            .opacity(isEnabled ? (isHeld ? 0.6 : 1) : 0.45)
            .gesture(
                DragGesture(minimumDistance: 0)
                    .onChanged { _ in begin() }
                    .onEnded { _ in end() }
            )
            .accessibilityAddTraits(.isButton)
            .accessibilityAction { if isEnabled { press() } }
            .onDisappear { end() }
    }

    private func begin() {
        guard !isHeld else { return }
        isHeld = true
        guard isEnabled else { return }
        press()
        repeater = Task {
            do { try await Task.sleep(for: .milliseconds(400)) } catch { return }
            while !Task.isCancelled {
                press()
                do { try await Task.sleep(for: .milliseconds(80)) } catch { return }
            }
        }
    }

    private func end() {
        isHeld = false
        repeater?.cancel()
        repeater = nil
    }
}
