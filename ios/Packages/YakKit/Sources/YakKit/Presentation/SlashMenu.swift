import Foundation

/// The commands menu above the message field, as a pure value: when it opens, what it lists, which row is selected
/// and what a pick writes into the field. It follows the web composer's rules.
public struct SlashMenu: Equatable, Sendable {
    public enum Section: Equatable, Sendable { case skills, commands, yak, other }

    public struct Group: Equatable, Sendable {
        public let section: Section
        public let commands: [SlashCommand]
    }

    public enum Direction: Sendable { case up, down }

    /// What a pick writes: the text of the field and where the caret goes (a UTF-16 offset, at the end).
    public struct Pick: Equatable, Sendable {
        public let text: String
        public let caret: Int
    }

    /// The name being typed, without the slash; empty right after the slash.
    public let query: String
    /// Matches in the order the menu draws them, one group per kind: skills, commands, Yak, then any kind this app
    /// does not know. Empty when nothing matches.
    public let groups: [Group]
    /// The highlighted command: the one asked for while it is still listed, else the first.
    public let selected: SlashCommand?

    /// Every listed command in drawing order, which is the order the arrow keys walk.
    public var rows: [SlashCommand] { groups.flatMap(\.commands) }
    /// A name was typed and no command has it: the menu says the text goes to Claude as it is.
    public var hasNoMatch: Bool { groups.isEmpty }

    /// The menu for `text`, or nil while it is closed. It opens when the whole field is a slash and a name without
    /// spaces, and the thread lists commands at all (`commands` nil is an older server: no menu, the slash is
    /// text). It stays closed after `isDismissed`, and while the slash is bare and nothing is listed.
    public static func make(commands: [SlashCommand]?, text: String, isDismissed: Bool, highlighted: String? = nil)
        -> SlashMenu?
    {
        guard let commands, !isDismissed, let query = query(of: text) else { return nil }
        let matches = filter(commands, query: query)
        guard !matches.isEmpty || !query.isEmpty else { return nil }
        let groups = Self.groups(of: matches)
        let rows = groups.flatMap(\.commands)
        let selected = rows.first { $0.name == highlighted } ?? rows.first
        return SlashMenu(query: query, groups: groups, selected: selected)
    }

    /// The text after a leading slash up to the first space, or nil once the field is anything else.
    public static func query(of text: String) -> String? {
        guard text.hasPrefix("/") else { return nil }
        let name = text.dropFirst()
        return name.contains(where: \.isWhitespace) ? nil : String(name)
    }

    /// Names that start with the query first, then names that contain it, each group in the server's order.
    public static func filter(_ commands: [SlashCommand], query: String) -> [SlashCommand] {
        let needle = query.lowercased()
        let prefix = commands.filter { $0.name.lowercased().hasPrefix(needle) }
        let inside = commands.filter {
            !$0.name.lowercased().hasPrefix(needle) && $0.name.lowercased().contains(needle)
        }
        return prefix + inside
    }

    /// The name to highlight after an arrow key, wrapping at both ends; nil when nothing is listed.
    public func moved(_ direction: Direction) -> String? {
        let rows = rows
        guard !rows.isEmpty else { return nil }
        let index = selected.flatMap { selected in rows.firstIndex { $0.name == selected.name } } ?? 0
        let next = direction == .down ? (index + 1) % rows.count : (index + rows.count - 1) % rows.count
        return rows[next].name
    }

    /// `/name ` with a space after it, as on the web, and the caret at the end. The space also ends the name, so the
    /// menu closes.
    public static func pick(_ command: SlashCommand) -> Pick {
        let text = "/" + command.name + " "
        return Pick(text: text, caret: text.utf16.count)
    }

    private static func groups(of matches: [SlashCommand]) -> [Group] {
        let order: [(Section, SlashCommandKind?)] = [
            (.skills, .skill), (.commands, .command), (.yak, .yak), (.other, nil),
        ]
        let known: Set<SlashCommandKind> = [.skill, .command, .yak]
        return order.compactMap { section, kind in
            let commands = matches.filter {
                kind.map { [command = $0] kind in command.kind == kind } ?? !known.contains($0.kind)
            }
            return commands.isEmpty ? nil : Group(section: section, commands: commands)
        }
    }
}

/// A key the commands menu may take from the field.
public enum SlashKey: Sendable { case up, down, returnKey, escape }

/// What a key does while the menu is open.
public enum SlashKeyAction: Equatable, Sendable {
    case move(SlashMenu.Direction)
    case pick(SlashCommand)
    case dismiss
}

public enum SlashKeyRouting {
    /// The action for a key, or nil when the field keeps the key: no menu, an input method composing text (it needs
    /// every key), arrows and Return with nothing listed, Return with no highlighted row.
    public static func action(for key: SlashKey, menu: SlashMenu?, isComposing: Bool) -> SlashKeyAction? {
        guard let menu, !isComposing else { return nil }
        switch key {
        case .escape: return .dismiss
        case .up: return menu.rows.isEmpty ? nil : .move(.up)
        case .down: return menu.rows.isEmpty ? nil : .move(.down)
        case .returnKey: return menu.selected.map { .pick($0) }
        }
    }

    /// The keys the menu registers for the hardware keyboard: all four while rows are listed, Escape alone in the
    /// no-match state, none while closed or composing. A registered key is taken from the field, so a key the menu
    /// cannot use is not registered.
    public static func keys(menu: SlashMenu?, isComposing: Bool) -> Set<SlashKey> {
        guard let menu, !isComposing else { return [] }
        return menu.rows.isEmpty ? [.escape] : [.up, .down, .returnKey, .escape]
    }
}

/// How tall the menu card may be.
public enum SlashMenuHeight {
    /// The room between the top of the screen's content and the field, never more than `fiveRows` and never less than
    /// `oneRow`, so a short window still shows one row to scroll. `barBottom` is the bottom edge of the composer bar
    /// (already above the keyboard), `cardHeight` the height of the composer card under the menu, `topReserve` what
    /// the header and a line of conversation keep.
    public static func cap(
        barBottom: Double, cardHeight: Double, spacing: Double, topReserve: Double, oneRow: Double, fiveRows: Double
    ) -> Double {
        min(fiveRows, max(oneRow, barBottom - cardHeight - spacing - topReserve))
    }
}
