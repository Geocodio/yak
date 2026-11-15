import Foundation

#if canImport(os)
    import os
#endif

/// One event of a thread's stream: `sequence` orders it, `type` and `data` carry it.
public struct ThreadEvent: Codable, Equatable, Sendable {
    public let sequence: Int
    public let type: String
    public let createdAt: String
    /// Kept as sent, so the cache writes it back unchanged.
    public let data: JSONValue
    /// Decoded from `data` at init; never encoded.
    public let payload: ThreadEventPayload

    public init(sequence: Int, type: String, createdAt: String, data: JSONValue) {
        self.sequence = sequence
        self.type = type
        self.createdAt = createdAt
        self.data = data
        payload = ThreadEventPayload.decode(type: type, data: data)
    }

    private enum Keys: String, CodingKey {
        case sequence, type, createdAt, data
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: Keys.self)
        self.init(
            sequence: try container.decode(Int.self, forKey: .sequence),
            type: try container.decode(String.self, forKey: .type),
            createdAt: try container.decodeIfPresent(String.self, forKey: .createdAt) ?? "",
            data: try container.decodeIfPresent(JSONValue.self, forKey: .data) ?? .null)
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: Keys.self)
        try container.encode(sequence, forKey: .sequence)
        try container.encode(type, forKey: .type)
        try container.encode(createdAt, forKey: .createdAt)
        try container.encode(data, forKey: .data)
    }

    /// False for the events the server only broadcasts: `delta` and `stream.reset`.
    public var isStored: Bool { type != "delta" && type != "stream.reset" }
}

public enum ThreadEventPayload: Equatable, Sendable {
    case entry(TranscriptEntry)
    case delta(StreamDelta)
    case streamReset
    case turn(TurnChange)
    case thread(ThreadStateChange)
    case messageState(MessageStateChange)
    case diffUpdated
    case child(ChildCard)
    case repository(RepositoryCard)
    case question(QuestionChange)
    /// An unknown type, or a known type whose data does not decode.
    case unknown

    static func decode(type: String, data: JSONValue) -> ThreadEventPayload {
        let bytes: Data
        do { bytes = try JSONEncoder().encode(data) } catch {
            logEventFailure("event data not encodable: \(Swift.type(of: error))")
            return .unknown
        }
        func make<Value: Decodable>(_ valueType: Value.Type) -> Value? {
            do { return try JSONDecoder().decode(valueType, from: bytes) } catch {
                logEventFailure("event data not decodable as \(valueType): \(Swift.type(of: error))")
                return nil
            }
        }
        switch type {
        case "entry": return make(TranscriptEntry.self).map { .entry($0) } ?? .unknown
        case "delta": return make(StreamDelta.self).map { .delta($0) } ?? .unknown
        case "stream.reset": return .streamReset
        case "turn": return make(TurnChange.self).map { .turn($0) } ?? .unknown
        case "thread": return make(ThreadStateChange.self).map { .thread($0) } ?? .unknown
        case "message.state": return make(MessageStateChange.self).map { .messageState($0) } ?? .unknown
        case "diff.updated": return .diffUpdated
        case "child": return make(ChildCard.self).map { .child($0) } ?? .unknown
        case "repository": return make(RepositoryCard.self).map { .repository($0) } ?? .unknown
        case "question": return make(QuestionChange.self).map { .question($0) } ?? .unknown
        default: return .unknown
        }
    }
}

public struct EventsPage: Decodable, Equatable, Sendable {
    public let events: [ThreadEvent]
    public let latestSequence: Int
    public let source: String
    /// How many elements the server sent, including the ones dropped as malformed.
    public let rawEventCount: Int

    public init(events: [ThreadEvent], latestSequence: Int, source: String, rawEventCount: Int? = nil) {
        self.events = events
        self.rawEventCount = rawEventCount ?? events.count
        self.latestSequence = latestSequence
        self.source = source
    }

    private enum Keys: String, CodingKey {
        case events, latestSequence, source
    }

    /// An element that is not a well-formed event is dropped, so one bad event never fails the page.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: Keys.self)
        let elements = try container.decode([LossyEvent].self, forKey: .events)
        events = elements.compactMap(\.event)
        rawEventCount = elements.count
        if events.count < elements.count { logDroppedEvents(elements.count - events.count) }
        latestSequence = try container.decode(Int.self, forKey: .latestSequence)
        source = try container.decodeIfPresent(String.self, forKey: .source) ?? ""
    }
}

private struct LossyEvent: Decodable {
    let event: ThreadEvent?
    init(from decoder: any Decoder) throws {
        do { event = try ThreadEvent(from: decoder) } catch {
            logEventFailure("event dropped: \(Swift.type(of: error))")
            event = nil
        }
    }
}

/// What the socket delivers on a thread channel: a whole event, or a pointer to fetch one that is too large.
public enum SocketEvent: Equatable, Sendable {
    case event(ThreadEvent)
    case pointer(sequence: Int)

    /// Nil when the payload is neither shape.
    public static func decode(_ data: Data) -> SocketEvent? {
        let value: JSONValue
        do { value = try JSONDecoder().decode(JSONValue.self, from: data) } catch {
            logEventFailure("socket payload not JSON: \(Swift.type(of: error))")
            return nil
        }
        if case .bool(true)? = value["pointer"], case .number(let number)? = value["sequence"],
            let sequence = Int(exactly: number)
        {
            return .pointer(sequence: sequence)
        }
        do { return .event(try JSONDecoder().decode(ThreadEvent.self, from: data)) } catch {
            logEventFailure("socket payload not an event: \(Swift.type(of: error))")
            return nil
        }
    }
}

/// Logs how many events a page dropped, never their content. Linux has no `os.Logger`, so it is a no-op there.
private func logDroppedEvents(_ count: Int) { logEventFailure("dropped \(count) malformed events") }

/// Logs a failure type, never event content. Linux has no `os.Logger`, so it is a no-op there.
private func logEventFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "ThreadEvent").error("\(message, privacy: .public)")
    #endif
}
