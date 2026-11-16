import Foundation

public enum ShareInput: Equatable, Sendable {
    /// Already prepared in the staging folder.
    case image(fileName: String)
    case link(URL)
    case text(String)
}

public enum ShareTarget: Equatable, Sendable { case newThread, lastThread }

public enum ShareNotice: Equatable, Sendable {
    case tooManyFiles(maximum: Int)
    case textCut(limit: Int)
    case linksDropped
}

public enum ShareProblem: Equatable, Sendable {
    case noContext, noLastThread
    case cannotSendToLastThread(title: String?)
    case noRepository
    case repositoryNotReady(slug: String, step: String?)
    case textTooShort(minimum: Int)
}

/// What the share sheet sends and the rules that cut and check it.
public struct ShareDraft: Equatable, Sendable {
    public static let messageLimit = 20_000
    public static let newThreadLimit = 10_000
    public static let newThreadMinimum = 3
    public static let linkLimit = 5

    private let typed: String
    private let inputs: [ShareInput]
    private let target: ShareTarget
    private let form: NewThreadForm?
    private let model: String?
    private let context: ShareContext?

    public init(
        typed: String, inputs: [ShareInput], target: ShareTarget, form: NewThreadForm?, model: String?,
        context: ShareContext?
    ) {
        self.typed = typed
        self.inputs = inputs
        self.target = target
        self.form = form
        self.model = model
        self.context = context
    }

    private var textLimit: Int { target == .newThread ? Self.newThreadLimit : Self.messageLimit }

    private var allImages: [String] {
        inputs.compactMap { if case .image(let name) = $0 { name } else { nil } }
    }

    private var allLinks: [URL] {
        inputs.compactMap { if case .link(let url) = $0 { url } else { nil } }
    }

    private var keptLinks: [URL] {
        Array(allLinks.filter { ["http", "https"].contains($0.scheme?.lowercased()) }.prefix(Self.linkLimit))
    }

    private var uncutText: String {
        let shared = inputs.compactMap { input -> String? in
            guard case .text(let text) = input else { return nil }
            let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
            return trimmed.isEmpty ? nil : trimmed
        }
        let links = keptLinks.map(\.absoluteString).joined(separator: "\n")
        let parts = [typed.trimmingCharacters(in: .whitespacesAndNewlines), links, shared.joined(separator: "\n\n")]
        return parts.filter { !$0.isEmpty }.joined(separator: "\n\n")
    }

    public var notices: [ShareNotice] {
        var result: [ShareNotice] = []
        if let context, allImages.count > context.limits.maxFiles {
            result.append(.tooManyFiles(maximum: context.limits.maxFiles))
        }
        if Self.codePointCount(uncutText) > textLimit { result.append(.textCut(limit: textLimit)) }
        if keptLinks.count != allLinks.count { result.append(.linksDropped) }
        return result
    }

    /// What is sent: the typed text, then the links (one per line), then the shared text.
    public var text: String { Self.cut(uncutText, toCodePoints: textLimit) }

    /// The server counts Unicode code points, so the limit does too; whole characters are kept.
    static func codePointCount(_ text: String) -> Int { text.unicodeScalars.count }

    static func cut(_ text: String, toCodePoints limit: Int) -> String {
        var result = ""
        var used = 0
        for character in text {
            let size = character.unicodeScalars.count
            if used + size > limit { break }
            result.append(character)
            used += size
        }
        return result
    }

    /// File names, at most `limits.maxFiles`.
    public var attachedImages: [String] {
        guard let context else { return [] }
        return Array(allImages.prefix(context.limits.maxFiles))
    }

    public var problem: ShareProblem? {
        guard let context else { return .noContext }
        switch target {
        case .lastThread:
            guard let last = context.lastThread else { return .noLastThread }
            if !last.canSend { return .cannotSendToLastThread(title: last.title) }
            // The server requires a message, also when images are attached.
            return text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? .textTooShort(minimum: 1) : nil
        case .newThread:
            guard let slug = form?.repo else { return .noRepository }
            // Without the loaded options there is nothing to check the repository against.
            guard let repository = context.options?.repos.first(where: { $0.slug == slug }) else {
                return .noRepository
            }
            if repository.setupStatus != "ready" {
                return .repositoryNotReady(slug: slug, step: repository.setupStep)
            }
            return Self.codePointCount(text.trimmingCharacters(in: .whitespacesAndNewlines)) < Self.newThreadMinimum
                ? .textTooShort(minimum: Self.newThreadMinimum) : nil
        }
    }

    /// The outbox item, with references numbered from the last thread's `nextAttachmentNumber`, or 1 for a new thread.
    /// Nil while `problem` is not nil.
    public func outboxDraft(sendKey: UUID, attachmentPath: (String) -> String) -> OutboxDraft? {
        guard problem == nil, let context else { return nil }
        let images = attachedImages
        let next = target == .lastThread ? (context.lastThread?.nextAttachmentNumber ?? 1) : 1
        let references = ComposerAttachments.references(isImage: images.map { _ in true }, next: next)
        var taken = Set<String>()
        let attachments = zip(images, references).map { name, reference in
            let safeName = ComposerAttachments.safeFileName(name)
            // A cleaned name already used gets "-2", "-3" before the extension, like `storeAttachment`.
            var unique = safeName
            var counter = 2
            while !taken.insert(unique).inserted {
                let dot = safeName.lastIndex(of: ".").flatMap { $0 == safeName.startIndex ? nil : $0 }
                let stem = dot.map { String(safeName[..<$0]) } ?? safeName
                let ending = dot.map { String(safeName[$0...]) } ?? ""
                unique = "\(stem)-\(counter)\(ending)"
                counter += 1
            }
            return OutboxAttachment(
                reference: reference, fileName: name, contentType: "image/jpeg", path: attachmentPath(unique))
        }
        let outboxTarget: OutboxTarget
        switch target {
        case .lastThread:
            guard let id = context.lastThread?.id else { return nil }
            outboxTarget = .thread(id)
        case .newThread:
            guard let draft = form?.draft else { return nil }
            outboxTarget = .newThread(draft)
        }
        return OutboxDraft(
            target: outboxTarget, text: text, model: model, delivery: .steer, attachments: attachments,
            sendKey: sendKey)
    }

    /// What the title row shows for the last thread: its title, or "A private thread".
    public static func lastThreadLabel(_ thread: ShareLastThread) -> String {
        guard !thread.isPrivate, let title = thread.title, !title.isEmpty else { return "A private thread" }
        return title
    }
}
