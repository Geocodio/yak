public enum ShortcutKey: Hashable, Sendable {
    case character(Character)
    case returnKey, escape, leftArrow, rightArrow
}

public struct ShortcutModifiers: OptionSet, Hashable, Sendable {
    public let rawValue: Int
    public init(rawValue: Int) { self.rawValue = rawValue }
    public static let command = ShortcutModifiers(rawValue: 1 << 0)
    public static let shift = ShortcutModifiers(rawValue: 1 << 1)
    public static let option = ShortcutModifiers(rawValue: 1 << 2)
    public static let control = ShortcutModifiers(rawValue: 1 << 3)
}

/// Where a shortcut applies. `.sheet` is a sheet, a dialog or a field being edited (the add-sub-thread sheet, Settings,
/// the preview's address field).
public enum ShortcutScope: Hashable, Sendable { case scene, composer, preview, files, editor, terminal, sheet }

/// Every keyboard shortcut of the app, declared once. The app writes them only through `yakShortcut(_:)`.
public enum AppShortcut: String, CaseIterable, Sendable {
    /// Command 1 to 5, in `PaneID` order.
    case focusChat, focusPreview, focusFiles, focusTerminal, focusOverview
    /// K, N, Shift N, comma, backslash, I.
    case searchThreads, newThread, newWindow, settings, toggleSidebar, overview
    /// Command Return, Command Shift Return, Command period.
    case send, sendAfterTurn, stop
    /// R, S, Option Left, Option Right.
    case reloadPreview, save, previousFile, nextFile
    /// T, W, F.
    case newShell, closeShell, findFile
    /// Command Return (in a sheet), Escape (in a sheet).
    case addSubThread, cancel

    public var key: ShortcutKey {
        switch self {
        case .focusChat: .character("1")
        case .focusPreview: .character("2")
        case .focusFiles: .character("3")
        case .focusTerminal: .character("4")
        case .focusOverview: .character("5")
        case .searchThreads: .character("k")
        case .newThread, .newWindow: .character("n")
        case .settings: .character(",")
        case .toggleSidebar: .character("\\")
        case .overview: .character("i")
        case .send, .sendAfterTurn, .addSubThread: .returnKey
        case .stop: .character(".")
        case .reloadPreview: .character("r")
        case .save: .character("s")
        case .previousFile: .leftArrow
        case .nextFile: .rightArrow
        case .newShell: .character("t")
        case .closeShell: .character("w")
        case .findFile: .character("f")
        case .cancel: .escape
        }
    }

    public var modifiers: ShortcutModifiers {
        switch self {
        case .newWindow, .sendAfterTurn: [.command, .shift]
        case .previousFile, .nextFile: [.command, .option]
        case .cancel: []
        default: .command
        }
    }

    public var scope: ShortcutScope {
        switch self {
        case .focusChat, .focusPreview, .focusFiles, .focusTerminal, .focusOverview, .searchThreads, .newThread,
            .newWindow, .settings, .toggleSidebar, .overview:
            .scene
        case .send, .sendAfterTurn, .stop: .composer
        case .reloadPreview: .preview
        case .save: .editor
        case .previousFile, .nextFile, .findFile: .files
        case .newShell, .closeShell: .terminal
        case .addSubThread, .cancel: .sheet
        }
    }

    /// The pane the five focus shortcuts go to, nil for every other case.
    public var pane: PaneID? {
        switch self {
        case .focusChat: .chat
        case .focusPreview: .preview
        case .focusFiles: .files
        case .focusTerminal: .terminal
        case .focusOverview: .overview
        default: nil
        }
    }
}
