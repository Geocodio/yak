import Foundation

/// Who handles a slash command: Yak itself (`clear`), a skill or a command Claude lists. A kind this app does not
/// know keeps the command.
public struct SlashCommandKind: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let yak = SlashCommandKind(rawValue: "yak")
    public static let skill = SlashCommandKind(rawValue: "skill")
    public static let command = SlashCommandKind(rawValue: "command")
}

/// One command the thread's composer menu lists. A row without a name, description or kind does not decode and is
/// dropped with its list; `argumentHint` can be null.
public struct SlashCommand: Codable, Equatable, Sendable, Identifiable {
    public let name: String
    public let description: String
    public let argumentHint: String?
    public let kind: SlashCommandKind
    public var id: String { name }

    public init(name: String, description: String, argumentHint: String? = nil, kind: SlashCommandKind) {
        self.name = name
        self.description = description
        self.argumentHint = argumentHint
        self.kind = kind
    }

    private enum CodingKeys: String, CodingKey { case name, description, argumentHint, kind }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        let name = try container.decode(String.self, forKey: .name)
        guard !name.isEmpty, !name.contains(where: \.isWhitespace) else {
            throw DecodingError.dataCorruptedError(forKey: .name, in: container, debugDescription: "empty name")
        }
        self.name = name
        description = try container.decode(String.self, forKey: .description)
        argumentHint = (try? container.decodeIfPresent(String.self, forKey: .argumentHint)) ?? nil
        kind = try container.decode(SlashCommandKind.self, forKey: .kind)
    }
}

/// The thread's commands as the server last captured them. A thread show from an older server has none.
public struct SlashCommands: Codable, Equatable, Sendable {
    public let commands: [SlashCommand]
    /// When Claude reported the list; nil while only Yak's own commands are known.
    public let capturedAt: String?

    public init(commands: [SlashCommand], capturedAt: String? = nil) {
        self.commands = commands
        self.capturedAt = capturedAt
    }

    private enum CodingKeys: String, CodingKey { case commands, capturedAt }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        commands =
            ((try? container.decodeIfPresent([Lossy<SlashCommand>].self, forKey: .commands)) ?? nil)?
            .compactMap(\.value) ?? []
        capturedAt = (try? container.decodeIfPresent(String.self, forKey: .capturedAt)) ?? nil
    }
}
