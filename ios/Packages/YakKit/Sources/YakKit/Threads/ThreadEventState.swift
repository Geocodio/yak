import Foundation

/// Everything a thread's events add up to, in sequence order. A port of the web client's `reducer.ts`.
/// A value type: the engine owns one and publishes copies.
public struct ThreadEventState: Equatable, Sendable {
    public struct StoredEntry: Equatable, Sendable {
        public let entry: TranscriptEntry
        public let sequence: Int
        public let createdAt: String
    }

    public struct StreamingBlock: Equatable, Sendable {
        public let key: String
        public let kind: String
        public var text: String
    }

    public struct Card<Data: Equatable & Sendable>: Equatable, Sendable {
        public let firstSequence: Int
        public var data: Data
    }

    /// Ascending by sequence.
    public private(set) var entries: [StoredEntry] = []
    /// In arrival order, one per key.
    public private(set) var streamingBlocks: [StreamingBlock] = []
    public private(set) var turn: TurnChange?
    public private(set) var thread: ThreadStateChange?
    /// Keyed by `childThreadId.value`.
    public private(set) var children: [String: Card<ChildCard>] = [:]
    /// Keyed by repository name.
    public private(set) var repositories: [String: Card<RepositoryCard>] = [:]
    /// The latest `message.state` per message uuid.
    public private(set) var messageStates: [String: String] = [:]
    public private(set) var questionOpen: Bool?
    /// What the cache keeps: every applied event that is stored on the server.
    public private(set) var storedEvents: [ThreadEvent] = []
    public private(set) var lastSequence: Int

    public init(lastSequence: Int = 0) {
        self.lastSequence = lastSequence
    }

    /// The `after` value for a catch-up when `incoming` skips past the next expected sequence.
    public func gap(before incoming: Int) -> Int? {
        incoming > lastSequence + 1 ? lastSequence : nil
    }

    /// Applies the events newer than `lastSequence`, in the order given; gaps inside the batch are allowed
    /// because a catch-up page from the database has none of the deltas. Returns the events it applied.
    @discardableResult
    public mutating func apply(_ events: [ThreadEvent], latestSequence: Int? = nil) -> [ThreadEvent] {
        var applied: [ThreadEvent] = []
        for event in events where event.sequence > lastSequence {
            applyOne(event)
            lastSequence = event.sequence
            if event.isStored { storedEvents.append(event) }
            applied.append(event)
        }
        if let latestSequence, latestSequence > lastSequence { lastSequence = latestSequence }
        return applied
    }

    private mutating func applyOne(_ event: ThreadEvent) {
        switch event.payload {
        case .delta(let delta):
            if let index = streamingBlocks.firstIndex(where: { $0.key == delta.blockKey }) {
                streamingBlocks[index].text += delta.text
            } else {
                streamingBlocks.append(StreamingBlock(key: delta.blockKey, kind: delta.kind, text: delta.text))
            }
        case .streamReset:
            streamingBlocks = []
        case .entry(let entry):
            if case .assistant(let messageId, _) = entry {
                let prefix = messageId + ":"
                streamingBlocks.removeAll { $0.key.hasPrefix(prefix) }
            }
            entries.append(StoredEntry(entry: entry, sequence: event.sequence, createdAt: event.createdAt))
        case .turn(let change):
            turn = change
        case .thread(let change):
            thread = change
        case .child(let card):
            let key = card.childThreadId.value
            children[key] = Card(firstSequence: children[key]?.firstSequence ?? event.sequence, data: card)
        case .repository(let card):
            repositories[card.repo] = Card(
                firstSequence: repositories[card.repo]?.firstSequence ?? event.sequence, data: card)
        case .messageState(let change):
            messageStates[change.uuid] = change.state
        case .question(let change):
            questionOpen = change.open
        case .diffUpdated, .unknown:
            break
        }
    }

    /// Adds a history page. Only events older than everything held are taken, so a repeated page changes
    /// nothing. Turn, thread, card data, message states, the open question and `lastSequence` stay as they are.
    public mutating func prependOlder(_ events: [ThreadEvent]) {
        let bound = min(entries.first?.sequence ?? .max, storedEvents.first?.sequence ?? .max)
        let older = events.filter { $0.sequence < bound }.sorted { $0.sequence < $1.sequence }
        var olderEntries: [StoredEntry] = []
        for event in older {
            switch event.payload {
            case .entry(let entry):
                olderEntries.append(StoredEntry(entry: entry, sequence: event.sequence, createdAt: event.createdAt))
            case .child(let card):
                let key = card.childThreadId.value
                if let held = children[key] {
                    children[key] = Card(firstSequence: min(held.firstSequence, event.sequence), data: held.data)
                } else {
                    children[key] = Card(firstSequence: event.sequence, data: card)
                }
            case .repository(let card):
                if let held = repositories[card.repo] {
                    repositories[card.repo] = Card(
                        firstSequence: min(held.firstSequence, event.sequence), data: held.data)
                } else {
                    repositories[card.repo] = Card(firstSequence: event.sequence, data: card)
                }
            default:
                break
            }
        }
        entries = olderEntries + entries
        storedEvents = older.filter(\.isStored) + storedEvents
    }
}
