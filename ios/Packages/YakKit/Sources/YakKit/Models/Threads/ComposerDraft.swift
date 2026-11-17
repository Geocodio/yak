import Foundation

/// Which composer a draft belongs to.
public enum ComposerDraftKey: Hashable, Sendable {
    case thread(String)
    case newThread
}

/// What a person typed and attached in a composer and has not sent.
public struct ComposerDraft: Codable, Equatable, Sendable {
    public var text: String
    public var model: String?
    public var effort: EffortLevel?
    /// Made when the first attachment is stored; the send uses it as the outbox item's id.
    public var sendKey: UUID?
    public var attachments: [OutboxAttachment]
    public var newThread: NewThreadDraft?

    public init(
        text: String = "", model: String? = nil, effort: EffortLevel? = nil, sendKey: UUID? = nil,
        attachments: [OutboxAttachment] = [], newThread: NewThreadDraft? = nil
    ) {
        self.text = text
        self.model = model
        self.effort = effort
        self.sendKey = sendKey
        self.attachments = attachments
        self.newThread = newThread
    }

    /// True when nothing is worth keeping: blank text, no attachments, no model and no new-thread choices.
    public var isEmpty: Bool {
        text.allSatisfy(\.isWhitespace) && attachments.isEmpty && model == nil && effort == nil && newThread == nil
    }
}
