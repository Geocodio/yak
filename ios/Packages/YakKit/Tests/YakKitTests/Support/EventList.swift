import Foundation
import Testing

@testable import YakKit

struct PointerAnswer: Decodable {
    let sequence: Int
    let event: ThreadEvent
}

struct EventListError: Error, CustomStringConvertible {
    let description: String
}

/// The server's event-list shape: what the socket delivers, the catch-up body, and the pointer answers.
struct EventList: Decodable {
    let scenario: String
    let source: String
    let broadcasts: [SocketEvent]
    let catchUp: EventsPage
    let pointers: [PointerAnswer]

    private enum Keys: String, CodingKey { case scenario, source, broadcasts, catchUp, pointers }

    init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: Keys.self)
        scenario = try container.decode(String.self, forKey: .scenario)
        source = try container.decode(String.self, forKey: .source)
        catchUp = try container.decode(EventsPage.self, forKey: .catchUp)
        pointers = try container.decode([PointerAnswer].self, forKey: .pointers)
        var items: [SocketEvent] = []
        for (index, raw) in try container.decode([JSONValue].self, forKey: .broadcasts).enumerated() {
            guard let item = SocketEvent.decode(try JSONEncoder().encode(raw)) else {
                throw EventListError(description: "broadcast \(index) is neither an event nor a pointer")
            }
            items.append(item)
        }
        broadcasts = items
    }

    /// A scenario from `Scenarios/`.
    static func load(_ name: String) throws -> EventList {
        try JSONDecoder().decode(EventList.self, from: Scenarios.data(name))
    }

    /// A server fixture, read through `Fixtures`.
    static func loadFixture(_ name: String) throws -> EventList {
        try JSONDecoder().decode(EventList.self, from: Fixtures.data(name))
    }

    /// The broadcasts in order, each pointer replaced by its event from `pointers`.
    var events: [ThreadEvent] {
        broadcasts.compactMap { item in
            switch item {
            case .event(let event): return event
            case .pointer(let sequence):
                guard let answer = pointers.first(where: { $0.sequence == sequence }) else {
                    Issue.record("pointer \(sequence) has no entry in `pointers`")
                    return nil
                }
                return answer.event
            }
        }
    }

    /// The stored events, as the catch-up returns them.
    var stored: [ThreadEvent] { catchUp.events }
}
