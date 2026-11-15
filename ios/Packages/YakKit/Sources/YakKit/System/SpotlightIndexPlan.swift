import Foundation

/// The instance whose threads are written to the index.
public struct SpotlightInstance: Equatable, Sendable {
    public let id: UUID
    public let name: String
    public init(id: UUID, name: String) {
        self.id = id
        self.name = name
    }
}

public struct SpotlightEntry: Equatable, Sendable {
    /// "<instance uuid>/<thread id>"; `SpotlightIndexPlan.link(forIdentifier:instances:)` reads it back.
    public let identifier: String
    /// The instance's uuid string, so one instance's entries are erased together.
    public let domain: String
    /// Cleaned, at most 200 characters.
    public let title: String
    /// Cleaned, at most 80 characters.
    public let instanceName: String
    /// The time the entry leaves the index by itself: now plus 30 days.
    public let expiresAt: Date

    public init(identifier: String, domain: String, title: String, instanceName: String, expiresAt: Date) {
        self.identifier = identifier
        self.domain = domain
        self.title = title
        self.instanceName = instanceName
        self.expiresAt = expiresAt
    }
}

public enum SpotlightChange: Equatable, Sendable {
    case eraseAll
    /// Sorted by uuid string.
    case erase(domains: [UUID])
    /// Erase that domain, then add the entries.
    case replace(domain: UUID, entries: [SpotlightEntry])
}

/// What the index may hold, written before each change so it never claims less than the index has.
public struct SpotlightIndexRecord: Codable, Equatable, Sendable {
    public var indexedDomains: Set<UUID>
    public var pendingErasures: Set<UUID>
    public var pendingEraseAll: Bool
    /// Whether the last write may have included private titles.
    public var includedPrivate: Bool

    public init(
        indexedDomains: Set<UUID> = [], pendingErasures: Set<UUID> = [],
        pendingEraseAll: Bool = false, includedPrivate: Bool = false
    ) {
        self.indexedDomains = indexedDomains
        self.pendingErasures = pendingErasures
        self.pendingEraseAll = pendingEraseAll
        self.includedPrivate = includedPrivate
    }

    public static let empty = SpotlightIndexRecord()
    public static let maximumDomains = 64

    /// Nil, garbage, or more than 64 ids in either set: `.empty` with `pendingEraseAll` set.
    public static func decode(_ data: Data?) -> SpotlightIndexRecord {
        var unknown = SpotlightIndexRecord.empty
        unknown.pendingEraseAll = true
        guard let data, let record = try? JSONDecoder().decode(SpotlightIndexRecord.self, from: data),
            record.indexedDomains.count <= maximumDomains, record.pendingErasures.count <= maximumDomains
        else { return unknown }
        return record
    }

    /// An encoding failure cannot happen for these fields; an empty result decodes as "unknown, erase all".
    public func encoded() -> Data {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        return (try? encoder.encode(self)) ?? Data()
    }
}

/// Decides which thread titles may enter the Core Spotlight index and what to write or erase.
public enum SpotlightIndexPlan {
    public static let maximumEntriesPerInstance = 200
    public static let maximumTitleLength = 200
    public static let maximumInstanceNameLength = 80
    public static let maximumDepth = 16
    public static let lifetime: TimeInterval = 30 * 24 * 60 * 60

    public static func entries(
        lists: [ThreadList], instance: SpotlightInstance, includePrivate: Bool, now: Date
    ) -> [SpotlightEntry] {
        let instanceName = cleaned(instance.name, limit: maximumInstanceNameLength)
        let domain = instance.id.uuidString
        let expiresAt = now.addingTimeInterval(lifetime)
        var entries: [SpotlightEntry] = []
        var seen = Set<String>()
        let hidden = includePrivate ? [] : idsUnderPrivateRows(in: lists)

        for list in lists {
            for top in topRows(of: list) {
                var stack: [(row: ThreadRow, depth: Int)] = [(top, 0)]
                while let (row, depth) = stack.popLast() {
                    // A private row hides its whole subtree, whatever the children's own flags say.
                    if hidden.contains(row.id) { continue }
                    if ThreadIdentifier.isValid(row.id), !seen.contains(row.id) {
                        let title = cleaned(row.title, limit: maximumTitleLength)
                        if !title.isEmpty {
                            seen.insert(row.id)
                            entries.append(
                                SpotlightEntry(
                                    identifier: "\(domain)/\(row.id)", domain: domain, title: title,
                                    instanceName: instanceName, expiresAt: expiresAt))
                            if entries.count >= maximumEntriesPerInstance { return entries }
                        }
                    }
                    if depth < maximumDepth {
                        for child in row.children.reversed() { stack.append((child, depth + 1)) }
                    }
                }
            }
        }
        return entries
    }

    private static func topRows(of list: ThreadList) -> [ThreadRow] {
        var tops = list.starred
        for item in list.needsYou {
            if case .thread(let row) = item { tops.append(row) }
        }
        return tops + list.threads
    }

    /// The ids of every private row and every row below one, in any list, so a thread flagged public under a
    /// private parent stays out even where another list shows it as a top-level row.
    private static func idsUnderPrivateRows(in lists: [ThreadList]) -> Set<String> {
        var hidden = Set<String>()
        for list in lists {
            var stack: [(row: ThreadRow, depth: Int, underPrivate: Bool)] = topRows(of: list).map { ($0, 0, false) }
            while let (row, depth, underPrivate) = stack.popLast() {
                let isHidden = underPrivate || row.isPrivate
                if isHidden { hidden.insert(row.id) }
                if depth < maximumDepth {
                    for child in row.children { stack.append((child, depth + 1, isHidden)) }
                }
            }
        }
        return hidden
    }

    /// The one rule for whether private titles may enter the index. Pass this value, not the raw switch, to
    /// `recordingIntent` and `record`, so the record never claims more than `changes` writes.
    public static func allowsPrivateTitles(includePrivate: Bool, appLockOn: Bool) -> Bool {
        includePrivate && !appLockOn
    }

    public static func changes(
        record: SpotlightIndexRecord, paired: Set<UUID>,
        active: (instance: SpotlightInstance, lists: [ThreadList])?,
        includePrivate: Bool, appLockOn: Bool, now: Date
    ) -> [SpotlightChange] {
        let privateAllowed = allowsPrivateTitles(includePrivate: includePrivate, appLockOn: appLockOn)
        var result: [SpotlightChange] = []
        if record.pendingEraseAll || (record.includedPrivate && !privateAllowed) {
            result.append(.eraseAll)
        }
        let erasures = record.pendingErasures.union(record.indexedDomains.subtracting(paired))
        if !erasures.isEmpty {
            result.append(.erase(domains: erasures.sorted { $0.uuidString < $1.uuidString }))
        }
        if let active, paired.contains(active.instance.id) {
            result.append(
                .replace(
                    domain: active.instance.id,
                    entries: entries(
                        lists: active.lists, instance: active.instance, includePrivate: privateAllowed, now: now)))
        }
        return result
    }

    /// Called before the index is asked to forget a removed instance.
    public static func recordingRemoval(of instance: UUID, in record: SpotlightIndexRecord) -> SpotlightIndexRecord {
        var record = record
        record.pendingErasures.insert(instance)
        if record.pendingErasures.count > SpotlightIndexRecord.maximumDomains { record.pendingEraseAll = true }
        return record
    }

    /// Stored before a change is written. For `.replace`: the domain is in `indexedDomains`, and
    /// `includedPrivate` is set when `includePrivate` is true. Any other change: the record unchanged.
    /// `includePrivate` must be `allowsPrivateTitles(includePrivate:appLockOn:)`, never the raw switch.
    public static func recordingIntent(
        of change: SpotlightChange, includePrivate: Bool, in record: SpotlightIndexRecord
    ) -> SpotlightIndexRecord {
        guard case .replace(let domain, _) = change else { return record }
        var record = record
        record.indexedDomains.insert(domain)
        if record.indexedDomains.count > SpotlightIndexRecord.maximumDomains { record.pendingEraseAll = true }
        if includePrivate { record.includedPrivate = true }
        return record
    }

    /// `includePrivate` is the same effective value as for `recordingIntent`.
    public static func record(
        _ record: SpotlightIndexRecord, after change: SpotlightChange, succeeded: Bool, includePrivate: Bool
    ) -> SpotlightIndexRecord {
        guard succeeded else { return record }
        var record = record
        switch change {
        case .eraseAll:
            record.indexedDomains = []
            record.pendingErasures = []
            record.pendingEraseAll = false
            record.includedPrivate = false
        case .erase(let domains):
            record.indexedDomains.subtract(domains)
            record.pendingErasures.subtract(domains)
        case .replace:
            break
        }
        return record
    }

    /// `yak://thread?instance=<instance url>&id=<thread id>` for an identifier this plan made, nil for anything else.
    public static func link(forIdentifier identifier: String, instances: [Instance]) -> URL? {
        guard identifier.count <= 120 else { return nil }
        let parts = identifier.split(separator: "/", omittingEmptySubsequences: false)
        guard parts.count == 2, let id = UUID(uuidString: String(parts[0])),
            let instance = instances.first(where: { $0.id == id }), ThreadIdentifier.isValid(String(parts[1]))
        else { return nil }
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        components.queryItems = [
            URLQueryItem(name: "instance", value: instance.url.absoluteString),
            URLQueryItem(name: "id", value: String(parts[1])),
        ]
        return components.url
    }

    /// Control, separator and formatting characters become spaces, whitespace runs collapse,
    /// the ends are trimmed and the result is cut to `limit` grapheme clusters with a trailing "…".
    private static func cleaned(_ text: String, limit: Int) -> String {
        var scalars = String.UnicodeScalarView()
        var previousWasSpace = true
        for scalar in text.unicodeScalars.prefix(limit * 4) {
            let category = scalar.properties.generalCategory
            // Every format scalar (all bidirectional controls, zero-width space, byte order mark) becomes a
            // space, as in `LogRedactor.flatten`; the zero-width joiners stay so emoji and scripts that
            // need them still render.
            let isJoiner = scalar.value == 0x200C || scalar.value == 0x200D
            let isSpace =
                (category == .format && !isJoiner) || scalar.properties.isWhitespace || category == .control
                || category == .lineSeparator || category == .paragraphSeparator
            if isSpace {
                if !previousWasSpace { scalars.append(" ") }
                previousWasSpace = true
            } else {
                scalars.append(scalar)
                previousWasSpace = false
            }
        }
        var result = String(scalars)
        if result.last == " " { result.removeLast() }
        let characters = Array(result.prefix(limit + 1))
        guard characters.count > limit else { return result }
        return String(characters.prefix(limit - 1)) + "…"
    }
}
