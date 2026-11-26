import Foundation

/// Why a message from the page was refused. Never carries the message's content.
public enum EmbedMessageProblem: Error, Equatable, Sendable {
    /// Not a JSON object, or too large.
    case notAnObject
    case tooLarge
    /// `v` is missing or is not the version this app speaks.
    case unsupportedVersion
    /// `type` is missing or is not a short string.
    case missingType
    /// A known type whose payload is wrong: a missing field, a wrong type, a URL the rules refuse, a string over its limit.
    case malformed(type: String)
}

public enum EmbedPane: Equatable, Sendable {
    case files(mode: EmbedFilesMode, path: String?)
    /// `url` has passed `WebLinkRule`.
    case preview(url: URL?)
    case terminal
    case overview(section: EmbedOverviewSection?)
}

public enum EmbedFilesMode: String, Equatable, Sendable { case changed, everything }

public enum EmbedOverviewSection: String, Equatable, Sendable { case run, subthreads, files }

public enum EmbedCard: String, Equatable, Sendable {
    case question
    case coOwner = "co_owner"
    case repositoryQuestion = "repository_question"
    case stopped
    case earlyWarning = "early_warning"
}

public enum EmbedUnsentAction: String, Equatable, Sendable { case retry, edit, discard }

public enum EmbedErrorKind: String, Equatable, Sendable { case http, network, script }

/// A message the embedded thread page posts to the app, version 1. Built only from an untrusted value by `decode`.
public enum EmbedPageMessage: Equatable, Sendable {
    public static let version = 1
    /// Larger messages are refused before they are parsed.
    public static let maximumBytes = 64 * 1024
    public static let maximumURLLength = 2_048
    public static let maximumTitleLength = 300
    public static let maximumNameLength = 255

    case ready(threadID: String, path: String, latestSequence: Int, title: String)
    /// The Deployments and PR review pages are ready: they name neither a thread nor a feed position.
    case pageReady(path: String, title: String)
    case threadChanged(threadID: String, previousThreadID: String?)
    /// A URL that passed `WebLinkRule`, so an https address.
    case link(URL)
    case openPane(EmbedPane)
    /// `url` is on the instance's own origin. `name` is safe as a file name.
    case quickLook(url: URL, name: String, mimeType: String?)
    case cardAnswered(card: EmbedCard, action: String, isOK: Bool)
    case unsentAction(key: UUID, action: EmbedUnsentAction)
    /// The web session ended; the app signs in again.
    case authExpired
    /// The page looked for the element a `focus` message named and did not find it (an older message that is not
    /// loaded, a card that is gone).
    case focusMissed(fragment: String)
    /// How many agents of the thread run, and the fragment of the newest running agent's card (nil when none runs).
    case agents(running: Int, newest: String?)
    case error(kind: EmbedErrorKind, status: Int?)
    /// A type or a variant this app does not know; it is ignored.
    case unknown(type: String)
}

extension EmbedPageMessage {
    /// What `WKScriptMessage.body` holds: a dictionary of strings, numbers, booleans and nulls. `instanceURL` is the
    /// instance's address, which `quickLook` must stay on.
    public static func decode(
        _ body: Any, instanceURL: URL, allowsLoopbackHTTP: Bool = false
    ) throws(EmbedMessageProblem) -> EmbedPageMessage {
        guard body is [String: Any], JSONSerialization.isValidJSONObject(body),
            let data = try? JSONSerialization.data(withJSONObject: body)
        else { throw .notAnObject }
        return try decode(json: data, instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
    }

    public static func decode(
        json: Data, instanceURL: URL, allowsLoopbackHTTP: Bool = false
    ) throws(EmbedMessageProblem) -> EmbedPageMessage {
        guard json.count <= maximumBytes else { throw .tooLarge }
        let decoder = JSONDecoder()
        guard let envelope = try? decoder.decode(Envelope.self, from: json) else { throw .notAnObject }
        guard envelope.v == version else { throw .unsupportedVersion }
        guard let type = envelope.type, !type.isEmpty, type.utf8.count <= 64 else { throw .missingType }
        let context = Context(instanceURL: instanceURL, allowsLoopbackHTTP: allowsLoopbackHTTP)
        func payload<Value: Decodable>(_ kind: Value.Type) throws(EmbedMessageProblem) -> Value {
            guard let value = try? decoder.decode(kind, from: json) else { throw .malformed(type: type) }
            return value
        }
        let message: EmbedPageMessage?
        switch type {
        case "ready": message = try payload(Ready.self).message()
        case "threadChanged": message = try payload(ThreadChanged.self).message()
        case "link": message = try payload(Link.self).message()
        case "openPane": message = try payload(OpenPane.self).message()
        case "quickLook": message = try payload(QuickLook.self).message(context)
        case "cardAnswered": message = try payload(CardAnswered.self).message()
        case "unsentAction": message = try payload(UnsentAction.self).message()
        case "auth": message = try payload(Auth.self).message()
        case "focusMissed": message = try payload(FocusMissed.self).message()
        case "agents": message = try payload(Agents.self).message()
        case "error": message = try payload(ErrorReport.self).message()
        default: return .unknown(type: String(type.prefix(64)))
        }
        guard let message else { throw .malformed(type: type) }
        return message
    }

    // MARK: Payloads

    private struct Context {
        let instanceURL: URL
        let allowsLoopbackHTTP: Bool
    }

    /// A field of the wrong type reads as absent, so the caller names the problem (version or type).
    private struct Envelope: Decodable {
        let v: Int?
        let type: String?

        enum CodingKeys: String, CodingKey { case v, type }

        init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            v = try? container.decode(Int.self, forKey: .v)
            type = try? container.decode(String.self, forKey: .type)
        }
    }

    /// A value is nil in the result when it fails its rule, which makes the whole message malformed. A discriminator
    /// that is not known (a newer page) gives `.unknown` instead.
    private struct Ready: Decodable {
        let threadId: String?
        let url: String
        let latestSequence: Int?
        let title: String
        /// An explicit null for `threadId` or `latestSequence` is refused; only an absent field means the page form.
        let hasNull: Bool

        enum CodingKeys: String, CodingKey { case threadId, url, latestSequence, title }

        init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            threadId = try container.decodeIfPresent(String.self, forKey: .threadId)
            url = try container.decode(String.self, forKey: .url)
            latestSequence = try container.decodeIfPresent(Int.self, forKey: .latestSequence)
            title = try container.decode(String.self, forKey: .title)
            hasNull =
                try [CodingKeys.threadId, .latestSequence].contains {
                    try container.contains($0) && container.decodeNil(forKey: $0)
                }
        }

        /// A thread page names its thread and feed position; the other pages carry neither, and one without the other
        /// is malformed. A path starting with a second slash or a backslash would read as another host.
        func message() -> EmbedPageMessage? {
            guard !hasNull, url.hasPrefix("/"), !url.hasPrefix("//"), !url.contains("\\"),
                url.utf8.count <= maximumURLLength
            else { return nil }
            let cutTitle = String(title.prefix(maximumTitleLength))
            switch (threadId, latestSequence) {
            case (nil, nil): return .pageReady(path: url, title: cutTitle)
            case (let threadId?, let latestSequence?):
                guard ThreadIdentifier.isValid(threadId), latestSequence >= 0 else { return nil }
                return .ready(threadID: threadId, path: url, latestSequence: latestSequence, title: cutTitle)
            default: return nil
            }
        }
    }

    private struct ThreadChanged: Decodable {
        let threadId: String
        let previousThreadId: String?

        func message() -> EmbedPageMessage? {
            guard ThreadIdentifier.isValid(threadId), previousThreadId.map(ThreadIdentifier.isValid) ?? true
            else { return nil }
            return .threadChanged(threadID: threadId, previousThreadID: previousThreadId)
        }
    }

    private struct Link: Decodable {
        let url: String

        func message() -> EmbedPageMessage? {
            WebLinkRule.safeURL(url).map { .link($0) }
        }
    }

    private struct OpenPane: Decodable {
        let pane: String
        let mode: String?
        let path: String?
        let url: String?
        let section: String?

        func message() -> EmbedPageMessage? {
            switch pane {
            case "files":
                guard let mode = mode.flatMap(EmbedFilesMode.init(rawValue:)),
                    (path?.utf8.count ?? 0) <= maximumURLLength, path?.isEmpty != true
                else { return nil }
                return .openPane(.files(mode: mode, path: path))
            case "preview":
                guard let url else { return .openPane(.preview(url: nil)) }
                return WebLinkRule.safeURL(url).map { .openPane(.preview(url: $0)) }
            case "terminal":
                return .openPane(.terminal)
            case "overview":
                guard let section else { return .openPane(.overview(section: nil)) }
                return EmbedOverviewSection(rawValue: section).map { .openPane(.overview(section: $0)) }
            default:
                return .unknown(type: "openPane")
            }
        }
    }

    private struct QuickLook: Decodable {
        /// The page's own limit for a name, in UTF-16 units.
        static let maximumNameUnits = 500

        let url: String
        let name: String
        let mime: String?

        func message(_ context: Context) -> EmbedPageMessage? {
            guard url.utf8.count <= maximumURLLength, let components = URLComponents(string: url),
                let target = components.url, let scheme = components.scheme?.lowercased(),
                scheme == "https" || (scheme == "http" && context.allowsLoopbackHTTP && components.host == "127.0.0.1"),
                components.user == nil, components.password == nil,
                let authority = PreviewNavigation.authority(of: target),
                authority == PreviewNavigation.authority(of: context.instanceURL),
                name.utf16.count <= Self.maximumNameUnits,
                let safeName = Self.safeFileName(name)
            else { return nil }
            return .quickLook(url: target, name: safeName, mimeType: Self.validMimeType(mime))
        }

        /// Separators, control characters and format characters (such as a right-to-left override) become "_", and a
        /// name over `maximumNameLength` bytes is shortened at a character boundary, keeping its extension. Nil when
        /// nothing readable is left.
        static func safeFileName(_ name: String) -> String? {
            var cleaned = ""
            for scalar in name.unicodeScalars {
                let category = scalar.properties.generalCategory
                let isUnsafe = category == .control || category == .format || scalar == "/" || scalar == "\\"
                cleaned.unicodeScalars.append(isUnsafe ? "_" : scalar)
            }
            cleaned = cleaned.trimmingCharacters(in: .whitespaces)
            guard !cleaned.isEmpty, cleaned != ".", cleaned != ".." else { return nil }
            return shortened(cleaned)
        }

        private static func shortened(_ name: String) -> String {
            guard name.utf8.count > maximumNameLength else { return name }
            var extensionPart = ""
            var base = Substring(name)
            if let dot = name.lastIndex(of: "."), dot != name.startIndex {
                let candidate = name[dot...]
                if candidate.utf8.count <= 16 {
                    extensionPart = String(candidate)
                    base = name[..<dot]
                }
            }
            var result = ""
            var bytes = extensionPart.utf8.count
            for character in base {
                bytes += String(character).utf8.count
                if bytes > maximumNameLength { break }
                result.append(character)
            }
            return result + extensionPart
        }

        /// A `type/subtype` of at most 100 characters, or nil; the type is a hint, so a bad one is dropped.
        static func validMimeType(_ value: String?) -> String? {
            guard let value, value.utf8.count <= 100 else { return nil }
            let parts = value.split(separator: "/", omittingEmptySubsequences: false)
            let allowed = Set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$&-^_.+".utf8)
            guard parts.count == 2, parts.allSatisfy({ !$0.isEmpty && $0.utf8.allSatisfy(allowed.contains) })
            else { return nil }
            return value.lowercased()
        }
    }

    private struct CardAnswered: Decodable {
        let card: String
        let action: String
        let ok: Bool

        func message() -> EmbedPageMessage? {
            guard let known = EmbedCard(rawValue: card) else { return .unknown(type: "cardAnswered") }
            guard !action.isEmpty, action.utf8.count <= 64 else { return nil }
            return .cardAnswered(card: known, action: action, isOK: ok)
        }
    }

    private struct UnsentAction: Decodable {
        let key: String
        let action: String

        func message() -> EmbedPageMessage? {
            guard let id = UUID(uuidString: key), key.utf8.count == 36 else { return nil }
            guard let known = EmbedUnsentAction(rawValue: action) else { return .unknown(type: "unsentAction") }
            return .unsentAction(key: id, action: known)
        }
    }

    private struct Auth: Decodable {
        let state: String

        func message() -> EmbedPageMessage? {
            state == "expired" ? .authExpired : .unknown(type: "auth")
        }
    }

    private struct FocusMissed: Decodable {
        let fragment: String

        func message() -> EmbedPageMessage? {
            EmbedAppMessage.isValidFragment(fragment) ? .focusMissed(fragment: fragment) : nil
        }
    }

    private struct Agents: Decodable {
        let running: Int
        /// An absent or null `newest` both mean no running agent; the page itself always sends null.
        let newest: String?

        func message() -> EmbedPageMessage? {
            guard running >= 0, newest.map(EmbedAppMessage.isValidFragment) ?? true else { return nil }
            return .agents(running: running, newest: newest)
        }
    }

    private struct ErrorReport: Decodable {
        let kind: String
        let status: Int?

        func message() -> EmbedPageMessage? {
            guard let known = EmbedErrorKind(rawValue: kind) else { return .unknown(type: "error") }
            guard status.map({ (100...599).contains($0) }) ?? true else { return nil }
            return .error(kind: known, status: status)
        }
    }
}
