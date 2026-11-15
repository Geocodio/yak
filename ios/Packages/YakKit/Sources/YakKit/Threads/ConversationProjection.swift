import Foundation

/// The conversation items a thread's event state adds up to. A port of the web client's
/// `projectConversation.ts`.
public struct ConversationProjection: Equatable, Sendable {
    public let items: [ConversationItem]
    /// The sequence of the user entry for each message uuid.
    public let readSequences: [String: Int]

    private struct ToolUse {
        let id: String
        let name: String
        let input: [String: JSONValue]
    }

    private struct ToolResult {
        let text: String
        let isError: Bool
    }

    private static let editTools: Set<String> = ["Edit", "MultiEdit", "Write"]
    private static let verbs: [String: (verb: String, singular: String, plural: String)] = [
        "Read": ("Read", "file", "files"),
        "Bash": ("Ran", "command", "commands"),
        "Grep": ("Searched", "pattern", "patterns"),
        "Glob": ("Searched", "pattern", "patterns"),
    ]
    private static let categories: [String: ToolStep.Category] = [
        "Bash": .command, "Read": .read, "Grep": .search, "Glob": .search, "WebFetch": .fetch, "WebSearch": .fetch,
    ]

    /// Projects every entry on each call. Keep finished items per entry if Instruments shows the cost.
    public static func project(_ state: ThreadEventState) -> ConversationProjection {
        var results: [String: ToolResult] = [:]
        for stored in state.entries {
            if case .toolResult(let toolUseId, let isError, let text) = stored.entry {
                results[toolUseId] = ToolResult(text: text, isError: isError)
            }
        }

        var model: String?
        var lastAssistantText = ""
        var personSpoke = false
        var items: [ConversationItem] = []
        var readSequences: [String: Int] = [:]

        for stored in state.entries {
            let sequence = stored.sequence
            switch stored.entry {
            case .assistant(let messageId, let blocks):
                for var item in projectAssistant(messageId, blocks, sequence, results, model) {
                    if !stored.createdAt.isEmpty { item.at = stored.createdAt }
                    if item.kind == .tools, let previous = items.last, previous.kind == .tools, !personSpoke {
                        items[items.count - 1] = join(previous, item)
                    } else {
                        items.append(item)
                    }
                    personSpoke = false
                }
                for case .text(let text, _) in blocks { lastAssistantText = text }
            case .system(_, let systemModel):
                if let systemModel, !systemModel.isEmpty { model = systemModel }
            case .result(_, _, let text, let html):
                // The result repeats the final assistant text; it is shown only when the turn had no such text.
                let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
                let isRepeat = !trimmed.isEmpty && lastAssistantText.contains(trimmed)
                lastAssistantText = ""
                if !isRepeat {
                    var item = ConversationItem(
                        kind: .result, key: "result:\(sequence)", sequence: sequence, text: text)
                    if let html, !html.isEmpty { item.html = html }
                    items.append(item)
                }
            case .user(let uuid, _, _):
                personSpoke = true
                if let uuid, !uuid.isEmpty { readSequences[uuid] = sequence }
            case .toolResult, .unknown:
                break
            }
        }

        for (_, card) in state.children.sorted(by: cardOrder) {
            var item = ConversationItem(
                kind: .child, key: "child:\(card.data.childThreadId.value)", sequence: card.firstSequence)
            item.child = card.data
            items.append(item)
        }
        for (_, card) in state.repositories.sorted(by: cardOrder) {
            var item = ConversationItem(
                kind: .repository, key: "repository:\(card.data.repo)", sequence: card.firstSequence)
            item.repository = card.data
            items.append(item)
        }

        let visible = state.streamingBlocks.filter { $0.kind == "text" || $0.kind == "thinking" }
        for (order, block) in visible.enumerated() {
            var item = ConversationItem(
                kind: block.kind == "thinking" ? .thinking : .text, key: block.key,
                sequence: state.lastSequence + 1 + order, text: block.text)
            item.isStreaming = true
            items.append(item)
        }

        return ConversationProjection(items: items, readSequences: readSequences)
    }

    private static func cardOrder<Data>(
        _ left: (key: String, value: ThreadEventState.Card<Data>),
        _ right: (key: String, value: ThreadEventState.Card<Data>)
    ) -> Bool {
        (left.value.firstSequence, left.key) < (right.value.firstSequence, right.key)
    }

    private static func projectAssistant(
        _ messageId: String, _ blocks: [AssistantBlock], _ sequence: Int, _ results: [String: ToolResult],
        _ model: String?
    ) -> [ConversationItem] {
        var items: [ConversationItem] = []
        var edits: [ToolUse] = []
        var run: (firstIndex: Int, tools: [ToolUse])?

        func closeRun() {
            guard let open = run else { return }
            var item = ConversationItem(
                kind: .tools, key: "tools:\(messageId):\(open.firstIndex)", sequence: sequence,
                text: summarize(open.tools.map(\.name)))
            item.steps = open.tools.map { step($0, results[$0.id]) }
            item.isRunning = open.tools.contains { results[$0.id] == nil }
            let detail = open.tools.compactMap { results[$0.id]?.text }
            if !detail.isEmpty { item.detail = detail.joined(separator: "\n\n") }
            items.append(item)
            run = nil
        }

        for (index, block) in blocks.enumerated() {
            switch block {
            case .toolUse(let id, let name, let input):
                let tool = ToolUse(id: id, name: name, input: input)
                if editTools.contains(name) {
                    edits.append(tool)
                } else {
                    var open = run ?? (firstIndex: index, tools: [])
                    open.tools.append(tool)
                    run = open
                }
            case .text(let text, let html):
                closeRun()
                // A block without text draws nothing, so it neither gets an author header nor splits a run of calls.
                let hasHTML = !(html ?? "").isEmpty
                if text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !hasHTML { continue }
                var item = ConversationItem(kind: .text, key: "\(messageId):\(index)", sequence: sequence, text: text)
                if hasHTML { item.html = html }
                if let model, !model.isEmpty { item.model = model }
                items.append(item)
            case .thinking(let text):
                closeRun()
                if text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { continue }
                items.append(
                    ConversationItem(kind: .thinking, key: "\(messageId):\(index)", sequence: sequence, text: text))
            case .unknown:
                closeRun()
            }
        }
        closeRun()

        if !edits.isEmpty { items.append(editItem(messageId, sequence, edits)) }
        return items
    }

    private static func label(_ tool: ToolUse) -> String {
        func pick(_ key: String) -> String {
            if case .string(let value)? = tool.input[key] { return value }
            return ""
        }
        let value: String
        switch tool.name {
        case "Bash": value = pick("command")
        case "Grep", "Glob": value = pick("pattern")
        case "WebFetch": value = pick("url")
        default: value = pick("file_path")
        }
        return value.isEmpty ? tool.name : value
    }

    private static func step(_ tool: ToolUse, _ result: ToolResult?) -> ToolStep {
        ToolStep(
            key: "tool:\(tool.id)", category: categories[tool.name] ?? .command, tool: tool.name, label: label(tool),
            isFailed: result?.isError ?? false, isRunning: result == nil, output: result?.text)
    }

    private static func summarize(_ names: [String]) -> String {
        var order: [String] = []
        var counts: [String: Int] = [:]
        for name in names {
            if counts[name] == nil { order.append(name) }
            counts[name, default: 0] += 1
        }
        return order.map { name in
            let count = counts[name] ?? 0
            let words = verbs[name] ?? ("Used", name, name)
            return "\(words.verb) \(count) \(count == 1 ? words.singular : words.plural)"
        }.joined(separator: ", ")
    }

    private static func lineCount(_ value: JSONValue?) -> Int {
        guard case .string(let text)? = value, !text.isEmpty else { return 0 }
        return text.components(separatedBy: "\n").count
    }

    private static func editItem(_ messageId: String, _ sequence: Int, _ edits: [ToolUse]) -> ConversationItem {
        var item = ConversationItem(kind: .edits, key: "edits:\(messageId)", sequence: sequence)
        for tool in edits {
            if case .string(let path)? = tool.input["file_path"], !item.files.contains(path) {
                item.files.append(path)
            }
            if tool.name == "Write" {
                item.added += lineCount(tool.input["content"])
                continue
            }
            var pairs: [[String: JSONValue]] = [tool.input]
            if tool.name == "MultiEdit", case .array(let list)? = tool.input["edits"] {
                pairs = list.compactMap { if case .object(let pair) = $0 { return pair } else { return nil } }
            }
            for pair in pairs {
                item.added += lineCount(pair["new_string"])
                item.removed += lineCount(pair["old_string"])
            }
        }
        return item
    }

    /// Joins a tool run onto the run just before it, so calls that no visible text separates are one group.
    private static func join(_ previous: ConversationItem, _ item: ConversationItem) -> ConversationItem {
        var joined = previous
        joined.steps = previous.steps + item.steps
        joined.text = summarize(joined.steps.map(\.tool))
        joined.isRunning = previous.isRunning || item.isRunning
        let detail = [previous.detail, item.detail].compactMap { $0 }
        joined.detail = detail.isEmpty ? nil : detail.joined(separator: "\n\n")
        return joined
    }
}
