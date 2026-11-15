import Foundation

/// A notification's `userInfo` as the server sends it (an alert payload) or as a foreign push may look.
public struct PushPayload: Decodable, Equatable, Sendable {
    /// Decodes an object `{title, body}` or a plain string (the body, with a nil title).
    public struct Alert: Decodable, Equatable, Sendable {
        public let title: String?
        public let body: String?

        private enum CodingKeys: String, CodingKey { case title, body }

        init(title: String?, body: String?) {
            self.title = title
            self.body = body
        }

        public init(from decoder: any Decoder) throws {
            if let text = try? decoder.singleValueContainer().decode(String.self) {
                title = nil
                body = text
                return
            }
            let container = try decoder.container(keyedBy: CodingKeys.self)
            title = try container.decodeIfPresent(String.self, forKey: .title)
            body = try container.decodeIfPresent(String.self, forKey: .body)
        }
    }

    public struct Aps: Decodable, Equatable, Sendable {
        public let alert: Alert?
        public let badge: Int?
        public let threadID: String?
        public let interruptionLevel: String?

        private enum CodingKeys: String, CodingKey {
            case alert, badge
            case threadID = "thread-id"
            case interruptionLevel = "interruption-level"
        }

        public init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            alert = try? container.decodeIfPresent(Alert.self, forKey: .alert)
            badge = try? container.decodeIfPresent(Int.self, forKey: .badge)
            threadID = try? container.decodeIfPresent(String.self, forKey: .threadID)
            interruptionLevel = try? container.decodeIfPresent(String.self, forKey: .interruptionLevel)
        }
    }

    public struct Yak: Decodable, Equatable, Sendable {
        public let instance: String
        public let event: PushEvent
        public let kind: PushKind
        public let id: String?
        public let path: String?
        public let needsYouCount: Int?

        private enum CodingKeys: String, CodingKey { case instance, event, kind, id, path, needsYouCount }

        /// The core members are strict, so a missing one drops the object. The optional members are
        /// read one by one, so one of the wrong type is nil and the push keeps its destination.
        public init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            instance = try container.decode(String.self, forKey: .instance)
            event = try container.decode(PushEvent.self, forKey: .event)
            kind = try container.decode(PushKind.self, forKey: .kind)
            id = try? container.decodeIfPresent(String.self, forKey: .id)
            path = try? container.decodeIfPresent(String.self, forKey: .path)
            needsYouCount = try? container.decodeIfPresent(Int.self, forKey: .needsYouCount)
        }
    }

    public let aps: Aps
    /// Nil when the push is not a Yak push, or its `yak` object is incomplete.
    public let yak: Yak?

    private enum CodingKeys: String, CodingKey { case aps, yak }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        aps = try container.decode(Aps.self, forKey: .aps)
        yak = try? container.decodeIfPresent(Yak.self, forKey: .yak)
    }

    public init(json: Data) throws(PushPayloadError) {
        guard (try? JSONSerialization.jsonObject(with: json, options: .fragmentsAllowed)) != nil else {
            throw .notJSON
        }
        guard let payload = try? JSONDecoder().decode(PushPayload.self, from: json) else { throw .notAPush }
        self = payload
    }
}

public enum PushPayloadError: Error, Equatable, Sendable { case notJSON, notAPush }

/// Finds the paired instance an address from the server names: its paired `url`, or its `canonicalURL`.
public enum InstanceMatch {
    public static func instance(for address: URL, in instances: [Instance]) -> Instance? {
        guard let normalized = InstanceAddress.normalize(address.absoluteString) else { return nil }
        return instance(forNormalized: normalized, in: instances)
    }

    /// An exact paired-address match wins over a canonical one, so one instance's reported address
    /// never captures another paired instance's pushes.
    static func instance(forNormalized address: URL, in instances: [Instance]) -> Instance? {
        instances.first { $0.url == address } ?? instances.first { $0.canonicalURL == address }
    }

    /// A `yak://` link whose `instance` item names a paired instance by its server address, rewritten to the paired `url`;
    /// any other URL unchanged.
    public static func pairedLink(
        _ url: URL, instances: [Instance], normalize: (String) -> URL? = InstanceAddress.normalize
    ) -> URL {
        guard url.scheme?.lowercased() == "yak",
            var components = URLComponents(url: url, resolvingAgainstBaseURL: false),
            var items = components.queryItems,
            let index = items.firstIndex(where: { $0.name == "instance" }),
            let value = items[index].value,
            let address = normalize(value),
            let match = instance(forNormalized: address, in: instances),
            match.url.absoluteString != value
        else { return url }
        items[index].value = match.url.absoluteString
        components.queryItems = items
        return components.url ?? url
    }
}

public enum PushDestination: Equatable, Sendable {
    case thread(Instance, id: String)
    case task(Instance, id: String)
    /// An account push: the path on that instance.
    case webPage(Instance, URL)
    /// A test push, or a thread push with a bad id: open the instance.
    case instance(Instance)
    /// Offer pairing with the address filled in, with or without an id.
    case unknownInstance(URL)

    public static func make(
        _ payload: PushPayload, instances: [Instance],
        normalize: (String) -> URL? = InstanceAddress.normalize
    ) -> PushDestination? {
        guard let yak = payload.yak, let address = normalize(yak.instance) else { return nil }
        guard let match = InstanceMatch.instance(forNormalized: address, in: instances) else {
            return .unknownInstance(address)
        }
        if yak.event == .test { return .instance(match) }
        switch yak.kind {
        case .thread:
            if let id = yak.id, isValidThreadID(id) { return .thread(match, id: id) }
            return .instance(match)
        case .task:
            if let id = yak.id, isValidThreadID(id) { return .task(match, id: id) }
            return .instance(match)
        default:
            if let path = yak.path, let url = safeURL(path: path, on: match) { return .webPage(match, url) }
            return .instance(match)
        }
    }

    private static func isValidThreadID(_ id: String) -> Bool {
        id.utf8.count == 8 && id.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber) }
    }

    private static func safeURL(path: String, on instance: Instance) -> URL? {
        guard path.hasPrefix("/"), !path.hasPrefix("//"), path.count <= 512,
            !path.contains("\\"), !path.contains("@"),
            let url = URL(string: instance.url.absoluteString + path),
            url.scheme == instance.url.scheme, url.host == instance.url.host, url.port == instance.url.port
        else { return nil }
        return url
    }
}

public struct VisibleThread: Hashable, Sendable {
    public let instance: URL
    public let id: String
    public init(instance: URL, id: String) {
        self.instance = instance
        self.id = id
    }
}

/// How a push shows while the app is in the foreground.
public struct PushForeground: Equatable, Sendable {
    public let showsBanner: Bool
    public let playsSound: Bool

    /// A thread push for the thread the person is looking at stays silent; every other push shows.
    public static func make(
        _ payload: PushPayload, visible: Set<VisibleThread>, instances: [Instance],
        normalize: (String) -> URL? = InstanceAddress.normalize
    ) -> PushForeground {
        if let yak = payload.yak, yak.kind == .thread, let id = yak.id,
            let address = normalize(yak.instance),
            let match = InstanceMatch.instance(forNormalized: address, in: instances),
            visible.contains(VisibleThread(instance: match.url, id: id))
        {
            return PushForeground(showsBanner: false, playsSound: false)
        }
        return PushForeground(showsBanner: true, playsSound: true)
    }
}

public struct DeliveredNotification: Equatable, Sendable {
    public let identifier: String
    public let payload: PushPayload?
    public init(identifier: String, payload: PushPayload?) {
        self.identifier = identifier
        self.payload = payload
    }
}

public enum PushCleanup {
    /// The delivered notifications that came from `instance`, to remove when it is unpaired or revoked.
    /// `paired` is every paired instance, so an address that exactly names another paired instance is left alone.
    public static func identifiers(
        in delivered: [DeliveredNotification], from instance: Instance, among paired: [Instance]? = nil,
        normalize: (String) -> URL? = InstanceAddress.normalize
    ) -> [String] {
        let candidates = paired ?? [instance]
        return delivered.compactMap { notification in
            guard let yak = notification.payload?.yak, let address = normalize(yak.instance),
                InstanceMatch.instance(forNormalized: address, in: candidates)?.id == instance.id
            else { return nil }
            return notification.identifier
        }
    }
}
