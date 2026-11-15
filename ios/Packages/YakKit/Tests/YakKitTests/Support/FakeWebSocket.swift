import Foundation
import Testing

@testable import YakKit

/// Pusher frames as the server sends them.
enum Frames {
    static func established(socketID: String, activityTimeout: Int) -> String {
        #"{"event":"pusher:connection_established","data":"{\"socket_id\":\"\#(socketID)\",\"activity_timeout\":\#(activityTimeout)}"}"#
    }

    static let ping = #"{"event":"pusher:ping","data":"{}"}"#

    static func error(code: Int) -> String {
        #"{"event":"pusher:error","data":"{\"code\":\#(code),\"message\":\"Test error\"}"}"#
    }

    static func subscribed(_ channel: String) -> String {
        #"{"event":"pusher_internal:subscription_succeeded","channel":"\#(channel)","data":"{}"}"#
    }

    static func threadEvent(channel: String, data: String) -> String {
        let escaped = data.replacingOccurrences(of: "\\", with: "\\\\").replacingOccurrences(of: "\"", with: "\\\"")
        return #"{"event":"thread.event","channel":"\#(channel)","data":"\#(escaped)"}"#
    }
}

/// Parks a waiting test until the actor's state changes. A spinning `Task.yield` inside an actor can
/// starve the actor's own queue, so waiters suspend instead. A one second tick re-checks the limit.
private struct Waiters {
    private var continuations: [CheckedContinuation<Void, Never>] = []

    mutating func add(_ continuation: CheckedContinuation<Void, Never>) { continuations.append(continuation) }

    mutating func takeAll() -> [CheckedContinuation<Void, Never>] {
        defer { continuations = [] }
        return continuations
    }
}

private struct ConnectionRefused: Error {}
private struct SocketClosed: Error {}

actor FakeWebSocketServer: WebSocketConnector {
    private(set) var connectedURLs: [URL] = []
    private var connections: [FakeConnection] = []
    private var refusals = 0
    private var waiters = Waiters()

    func refuseNext(_ count: Int) { refusals = count }

    nonisolated func connect(to url: URL) async throws -> any WebSocketConnection {
        try await accept(url)
    }

    private func accept(_ url: URL) throws -> FakeConnection {
        connectedURLs.append(url)
        if refusals > 0 {
            refusals -= 1
            throw ConnectionRefused()
        }
        let connection = FakeConnection()
        connections.append(connection)
        for waiter in waiters.takeAll() { waiter.resume() }
        return connection
    }

    /// The nth accepted connection, counted from 0. Fails the test instead of hanging.
    func waitForConnection(_ index: Int) async -> FakeConnection {
        let deadline = ContinuousClock.now + .seconds(10)
        while connections.count <= index {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for connection \(index)")
                fatalError("FakeWebSocketServer.waitForConnection timed out")
            }
            await park()
        }
        return connections[index]
    }

    private func park() async {
        let ticker = Task { [weak self] in
            do { try await Task.sleep(for: .seconds(1)) } catch { return }
            await self?.wake()
        }
        await withCheckedContinuation { waiters.add($0) }
        ticker.cancel()
    }

    private func wake() {
        for waiter in waiters.takeAll() { waiter.resume() }
    }

    var acceptedCount: Int { connections.count }
}

actor FakeConnection: WebSocketConnection {
    private(set) var sent: [String] = []
    private(set) var isClosed = false
    private var inbox: [String] = []
    private var receiver: CheckedContinuation<String, any Error>?
    private var isDropped = false
    private var waiters = Waiters()

    func send(_ text: String) async throws {
        if isDropped || isClosed { throw SocketClosed() }
        sent.append(text)
        wake()
    }

    func receive() async throws -> String {
        if !inbox.isEmpty { return inbox.removeFirst() }
        if isDropped || isClosed { throw SocketClosed() }
        return try await withCheckedThrowingContinuation { receiver = $0 }
    }

    func close() async {
        isClosed = true
        failReceiver()
        wake()
    }

    func serverSends(_ text: String) {
        if let waiting = receiver {
            receiver = nil
            waiting.resume(returning: text)
        } else {
            inbox.append(text)
        }
    }

    /// The server goes away: the pending `receive` throws.
    func drop() {
        isDropped = true
        failReceiver()
    }

    private func park() async {
        let ticker = Task { [weak self] in
            do { try await Task.sleep(for: .seconds(1)) } catch { return }
            await self?.wake()
        }
        await withCheckedContinuation { waiters.add($0) }
        ticker.cancel()
    }

    private func wake() {
        for waiter in waiters.takeAll() { waiter.resume() }
    }

    private func failReceiver() {
        let waiting = receiver
        receiver = nil
        waiting?.resume(throwing: SocketClosed())
    }

    func waitForSent(_ count: Int) async {
        let deadline = ContinuousClock.now + .seconds(10)
        while sent.count < count {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for \(count) sent frames; sent \(sent)")
                return
            }
            await park()
        }
    }

    func waitUntilClosed() async {
        let deadline = ContinuousClock.now + .seconds(10)
        while !isClosed {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for the connection to close")
                return
            }
            await park()
        }
    }
}

extension ManualClock {
    /// Waits until the only sleeper is the one ending at `offset`, so a handshake timer that is about
    /// to be cancelled cannot be mistaken for the backoff wait.
    func waitForOnlySleeper(endingAt offset: Duration) async {
        let deadline = ContinuousClock.now + .seconds(10)
        while sleeperDeadlines != [offset] {
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for the only sleeper to end at \(offset); have \(sleeperDeadlines)")
                return
            }
            await Task.yield()
        }
    }
}
