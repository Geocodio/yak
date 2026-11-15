import Foundation

public enum SubThreadDraftProblem: Equatable, Sendable {
    case noName, nameTooLong, noBrief, briefTooLong

    /// The sentence a screen shows beside the form.
    public var sentence: String {
        switch self {
        case .noName: "Give the sub-thread a name."
        case .nameTooLong: "The name is too long."
        case .noBrief: "Describe what the sub-thread should do."
        case .briefTooLong: "The description is too long."
        }
    }
}

/// The saved "add a sub-thread" form. `sendKey` is written before the first request leaves and kept until a
/// 201 or a definite refusal; while `sendKeyIsInFlight` is set the text is locked, so a repeat carries the
/// text the key was first sent with.
public struct SubThreadDraft: Codable, Equatable, Sendable {
    public var name: String
    public var brief: String
    public var kind: StackChildKind
    public var model: String?
    public var sendKey: UUID?
    public var sendKeyIsInFlight: Bool

    public static let maximumNameLength = 60
    public static let maximumBriefLength = 20_000

    public init(
        name: String = "", brief: String = "", kind: StackChildKind = .implement, model: String? = nil,
        sendKey: UUID? = nil, sendKeyIsInFlight: Bool = false
    ) {
        self.name = name
        self.brief = brief
        self.kind = kind
        self.model = model
        self.sendKey = sendKey
        self.sendKeyIsInFlight = sendKeyIsInFlight
    }

    public var trimmedName: String { name.trimmingCharacters(in: .whitespacesAndNewlines) }
    public var trimmedBrief: String { brief.trimmingCharacters(in: .whitespacesAndNewlines) }

    public var problem: SubThreadDraftProblem? {
        if trimmedName.isEmpty { return .noName }
        if trimmedName.count > Self.maximumNameLength { return .nameTooLong }
        if trimmedBrief.isEmpty { return .noBrief }
        if trimmedBrief.count > Self.maximumBriefLength { return .briefTooLong }
        return nil
    }

    public var isEmpty: Bool {
        trimmedName.isEmpty && trimmedBrief.isEmpty && model == nil && kind == .implement
    }
}
