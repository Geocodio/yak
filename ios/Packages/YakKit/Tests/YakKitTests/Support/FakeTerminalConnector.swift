import Foundation

@testable import YakKit

/// Hands out queued sockets or handshake failures; the last entry repeats once the queue is used up.
actor FakeTerminalConnector: TerminalSocketConnector {
    enum Outcome: Sendable {
        case socket(FakeTerminalSocket)
        case handshakeFails
    }

    struct Connection: Sendable {
        let url: URL
        let origin: String
        let handshakeTimeout: Duration
    }

    private var queue: [Outcome] = []
    private(set) var connections: [Connection] = []

    func enqueue(_ outcomes: Outcome...) { queue.append(contentsOf: outcomes) }

    func connect(to url: URL, origin: String, handshakeTimeout: Duration) async throws -> any TerminalSocket {
        connections.append(Connection(url: url, origin: origin, handshakeTimeout: handshakeTimeout))
        let outcome = queue.count > 1 ? queue.removeFirst() : (queue.first ?? .handshakeFails)
        switch outcome {
        case .socket(let socket): return socket
        case .handshakeFails: throw TerminalSocketClosed(code: nil)
        }
    }
}
