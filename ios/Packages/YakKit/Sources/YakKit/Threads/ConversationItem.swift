import Foundation

/// One tool call of a `tools` item, as the step rows draw it.
public struct ToolStep: Equatable, Sendable {
    public enum Category: String, Sendable { case command, read, search, fetch }

    /// `tool:<id>`.
    public let key: String
    public let category: Category
    public let tool: String
    /// The Bash command, the Grep or Glob pattern, the WebFetch url, else the file path, else the tool name.
    public let label: String
    public let isFailed: Bool
    /// True while the call has no result.
    public let isRunning: Bool
    public let output: String?

    public init(
        key: String,
        category: Category,
        tool: String,
        label: String,
        isFailed: Bool,
        isRunning: Bool,
        output: String? = nil
    ) {
        self.key = key
        self.category = category
        self.tool = tool
        self.label = label
        self.isFailed = isFailed
        self.isRunning = isRunning
        self.output = output
    }
}

/// One thing the conversation draws, produced by `ConversationProjection`.
public struct ConversationItem: Equatable, Sendable {
    public enum Kind: String, Sendable { case text, thinking, tools, edits, result, child, repository }

    public let kind: Kind
    public let key: String
    public let sequence: Int
    public var text: String?
    /// Server-rendered markdown for `text`; plain `text` is shown when absent.
    public var html: String?
    /// The tool results of a `tools` item, joined.
    public var detail: String?
    public var steps: [ToolStep] = []
    /// True while a `tools` item has a call without a result.
    public var isRunning = false
    /// True for items made from streaming blocks.
    public var isStreaming = false
    public var model: String?
    /// When the entry was recorded.
    public var at: String?
    public var files: [String] = []
    public var added = 0
    public var removed = 0
    public var child: ChildCard?
    public var repository: RepositoryCard?

    public init(kind: Kind, key: String, sequence: Int, text: String? = nil) {
        self.kind = kind
        self.key = key
        self.sequence = sequence
        self.text = text
    }
}
