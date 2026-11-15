import Foundation

/// One stored line of the transcript, read from an `entry` event's `data`.
public enum TranscriptEntry: Decodable, Equatable, Sendable {
    case assistant(messageId: String, blocks: [AssistantBlock])
    case toolResult(toolUseId: String, isError: Bool, text: String)
    case system(subtype: String, model: String?)
    case user(uuid: String?, text: String, kind: String)
    case result(subtype: String, isError: Bool, text: String, html: String?)
    case unknown(role: String)

    private enum Keys: String, CodingKey {
        case role, messageId, blocks, toolUseId, isError, text, subtype, model, uuid, kind, html
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: Keys.self)
        let role = try container.decode(String.self, forKey: .role)
        switch role {
        case "assistant":
            let blocks = try container.decodeIfPresent([LossyBlock].self, forKey: .blocks) ?? []
            self = .assistant(
                messageId: try container.decode(String.self, forKey: .messageId), blocks: blocks.map(\.block))
        case "tool_result":
            self = .toolResult(
                toolUseId: try container.decode(String.self, forKey: .toolUseId),
                isError: try container.decodeIfPresent(Bool.self, forKey: .isError) ?? false,
                text: try container.decodeIfPresent(String.self, forKey: .text) ?? "")
        case "system":
            self = .system(
                subtype: try container.decodeIfPresent(String.self, forKey: .subtype) ?? "",
                model: try container.decodeIfPresent(String.self, forKey: .model))
        case "user":
            self = .user(
                uuid: try container.decodeIfPresent(String.self, forKey: .uuid),
                text: try container.decodeIfPresent(String.self, forKey: .text) ?? "",
                kind: try container.decodeIfPresent(String.self, forKey: .kind) ?? "prompt")
        case "result":
            self = .result(
                subtype: try container.decodeIfPresent(String.self, forKey: .subtype) ?? "",
                isError: try container.decodeIfPresent(Bool.self, forKey: .isError) ?? false,
                text: try container.decodeIfPresent(String.self, forKey: .text) ?? "",
                html: try container.decodeIfPresent(String.self, forKey: .html))
        default:
            self = .unknown(role: role)
        }
    }
}

public enum AssistantBlock: Decodable, Equatable, Sendable {
    case text(String, html: String?)
    case thinking(String)
    case toolUse(id: String, name: String, input: [String: JSONValue])
    case unknown(type: String)

    private enum Keys: String, CodingKey {
        case type, text, html, thinking, id, name, input
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: Keys.self)
        let type = try container.decode(String.self, forKey: .type)
        switch type {
        case "text":
            self = .text(
                try container.decode(String.self, forKey: .text),
                html: try container.decodeIfPresent(String.self, forKey: .html))
        case "thinking":
            let text =
                try container.decodeIfPresent(String.self, forKey: .text)
                ?? container.decode(String.self, forKey: .thinking)
            self = .thinking(text)
        case "tool_use":
            self = .toolUse(
                id: try container.decode(String.self, forKey: .id),
                name: try container.decode(String.self, forKey: .name),
                input: try container.decodeIfPresent([String: JSONValue].self, forKey: .input) ?? [:])
        default:
            self = .unknown(type: type)
        }
    }
}

/// A block that does not fit its type becomes `.unknown`, so one bad block never drops the entry.
private struct LossyBlock: Decodable {
    let block: AssistantBlock

    init(from decoder: any Decoder) throws {
        // Decode probe: an unknown or malformed block becomes .unknown instead of failing the whole entry.
        if let block = try? AssistantBlock(from: decoder) {
            self.block = block
        } else {
            let type = (try? decoder.container(keyedBy: AnyKey.self))
                .flatMap { try? $0.decode(String.self, forKey: AnyKey("type")) }
            block = .unknown(type: type ?? "")
        }
    }
}

private struct AnyKey: CodingKey {
    let stringValue: String
    var intValue: Int? { nil }
    init(_ value: String) { stringValue = value }
    init?(stringValue: String) { self.stringValue = stringValue }
    init?(intValue: Int) { return nil }
}
