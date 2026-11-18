import SwiftUI
import YakKit

extension AppShortcut {
    /// The name the system's shortcut overlay and the controls show.
    var title: LocalizedStringResource {
        switch self {
        case .focusChat: "shortcut.focusChat"
        case .focusPreview: "shortcut.focusPreview"
        case .focusFiles: "shortcut.focusFiles"
        case .focusTerminal: "shortcut.focusTerminal"
        case .focusOverview: "shortcut.focusOverview"
        case .searchThreads: "shortcut.searchThreads"
        case .newThread: "shortcut.newThread"
        case .newWindow: "shortcut.newWindow"
        case .settings: "shortcut.settings"
        case .toggleSidebar: "shortcut.toggleSidebar"
        case .overview: "shortcut.overview"
        case .send: "shortcut.send"
        case .sendAfterTurn: "shortcut.sendAfterTurn"
        case .stop: "shortcut.stop"
        case .reloadPreview: "shortcut.reloadPreview"
        case .save: "shortcut.save"
        case .previousFile: "shortcut.previousFile"
        case .nextFile: "shortcut.nextFile"
        case .newShell: "shortcut.newShell"
        case .closeShell: "shortcut.closeShell"
        case .findFile: "shortcut.findFile"
        case .addSubThread: "shortcut.addSubThread"
        case .cancel: "shortcut.cancel"
        }
    }

    var keyEquivalent: KeyEquivalent {
        switch key {
        case .character(let character): KeyEquivalent(character)
        case .returnKey: .return
        case .escape: .escape
        case .leftArrow: .leftArrow
        case .rightArrow: .rightArrow
        }
    }

    var eventModifiers: EventModifiers {
        var result: EventModifiers = []
        if modifiers.contains(.command) { result.insert(.command) }
        if modifiers.contains(.shift) { result.insert(.shift) }
        if modifiers.contains(.option) { result.insert(.option) }
        if modifiers.contains(.control) { result.insert(.control) }
        return result
    }
}

extension WindowTitle {
    /// The scene title: empty with none, the thread's title, or "A private thread", never a private thread's title.
    var text: String {
        switch self {
        case .none: ""
        case .thread(let title): title
        case .privateThread: String(localized: "window.privateThread")
        }
    }
}

private struct InputBlockedKey: EnvironmentKey { static let defaultValue = false }

extension EnvironmentValues {
    /// True while the app lock blocks input: every `yakShortcut` does nothing and no focus request is taken.
    var inputBlocked: Bool {
        get { self[InputBlockedKey.self] }
        set { self[InputBlockedKey.self] = newValue }
    }
}

/// Feeds the lock state to every shortcut under it; it reads the controller in its own body so it follows changes.
struct ShortcutGate: ViewModifier {
    let controller: AppLockController

    func body(content: Content) -> some View {
        content.environment(\.inputBlocked, controller.blocksInput)
    }
}

private struct YakShortcut: ViewModifier {
    let shortcut: AppShortcut
    @Environment(\.inputBlocked) private var blocked

    func body(content: Content) -> some View {
        content.keyboardShortcut(AppShortcutGate.keyboardShortcut(for: shortcut, blocked: blocked))
    }
}

enum AppShortcutGate {
    /// The one place a shortcut is dropped: while the lock blocks input it has no key equivalent at all.
    static func keyboardShortcut(for shortcut: AppShortcut, blocked: Bool) -> KeyboardShortcut? {
        blocked ? nil : KeyboardShortcut(shortcut.keyEquivalent, modifiers: shortcut.eventModifiers)
    }
}

extension View {
    /// Gives the control the shortcut. This is the only place the app writes `.keyboardShortcut(`.
    func yakShortcut(_ shortcut: AppShortcut) -> some View {
        modifier(YakShortcut(shortcut: shortcut))
    }
}
