import SwiftUI
import UIKit
import YakKit

/// Gives the commands menu the hardware keys the text view would take itself: Return and Escape (and the arrows). It
/// registers key commands with priority over the system behavior on the hosting view controller while the menu can use
/// them, and removes them as soon as it cannot, so the field keeps every key the menu has no use for. An input method
/// that is composing text (marked text in the text view) keeps every key: the commands are removed while it composes.
///
/// A line break from the software keyboard never passes through here; there Return stays a line break.
struct SlashKeyHook: UIViewRepresentable {
    /// The keys to register now.
    let keys: Set<SlashKey>
    /// Applies a key; the second value is whether the text view is composing. True when the menu took it.
    let handle: (SlashKey, Bool) -> Bool

    func makeUIView(context: Context) -> SlashKeyHookView {
        let view = SlashKeyHookView()
        view.isUserInteractionEnabled = false
        return view
    }

    func updateUIView(_ view: SlashKeyHookView, context: Context) {
        view.handle = handle
        view.wanted = keys
    }
}

@MainActor final class SlashKeyHookView: UIView {
    var handle: ((SlashKey, Bool) -> Bool)?
    var wanted: Set<SlashKey> = [] { didSet { sync() } }

    /// Whether a text view in the app has marked text, kept from the text change notifications.
    private var isComposing = false
    private var installed: [SlashKey: UIKeyCommand] = [:]
    private weak var host: UIViewController?
    private var observers: [any NSObjectProtocol] = []

    /// The hook whose commands are installed; the key commands' action finds it here.
    fileprivate static weak var current: SlashKeyHookView?

    override func didMoveToWindow() {
        super.didMoveToWindow()
        if window == nil {
            uninstall()
            observers.forEach(NotificationCenter.default.removeObserver)
            observers = []
        } else if observers.isEmpty {
            let names: [Notification.Name] = [
                UITextView.textDidChangeNotification, UITextField.textDidChangeNotification,
                UITextView.textDidEndEditingNotification, UITextField.textDidEndEditingNotification,
            ]
            observers = names.map { name in
                NotificationCenter.default.addObserver(forName: name, object: nil, queue: .main) { [weak self] note in
                    let input = note.object as? (any UITextInput)
                    MainActor.assumeIsolated { self?.compositionChanged(input?.markedTextRange != nil) }
                }
            }
            sync()
        }
    }

    private func compositionChanged(_ composing: Bool) {
        guard composing != isComposing else { return }
        isComposing = composing
        sync()
    }

    private func nearestViewController() -> UIViewController? {
        var responder: UIResponder? = self
        while let next = responder?.next {
            if let controller = next as? UIViewController { return controller }
            responder = next
        }
        return nil
    }

    /// Registers the wanted keys and removes the others; every key is removed while an input method composes.
    private func sync() {
        guard window != nil, let controller = host ?? nearestViewController() else { return }
        host = controller
        let target = isComposing ? [] : wanted
        for (key, command) in installed where !target.contains(key) {
            controller.removeKeyCommand(command)
            installed[key] = nil
        }
        for key in target where installed[key] == nil {
            let command = UIKeyCommand(
                action: #selector(UIViewController.yakSlashKeyCommand(_:)), input: Self.input(for: key),
                modifierFlags: [])
            command.wantsPriorityOverSystemBehavior = true
            controller.addKeyCommand(command)
            installed[key] = command
        }
        Self.current = installed.isEmpty ? Self.current : self
    }

    private func uninstall() {
        for command in installed.values { host?.removeKeyCommand(command) }
        installed = [:]
    }

    private static func input(for key: SlashKey) -> String {
        switch key {
        case .up: UIKeyCommand.inputUpArrow
        case .down: UIKeyCommand.inputDownArrow
        case .returnKey: "\r"
        case .escape: UIKeyCommand.inputEscape
        }
    }

    fileprivate func receive(input: String) {
        guard let key = installed.first(where: { Self.input(for: $0.key) == input })?.key else { return }
        _ = handle?(key, isComposing)
    }
}

extension UIViewController {
    /// The action of the commands menu's key commands; it reaches the hook that registered them.
    @objc fileprivate func yakSlashKeyCommand(_ command: UIKeyCommand) {
        guard let input = command.input else { return }
        MainActor.assumeIsolated { SlashKeyHookView.current?.receive(input: input) }
    }
}
