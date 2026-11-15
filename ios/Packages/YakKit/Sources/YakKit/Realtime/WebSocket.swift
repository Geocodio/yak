import Foundation

/// One open WebSocket. Text frames only.
public protocol WebSocketConnection: Sendable {
    func send(_ text: String) async throws
    /// Throws when the socket closes.
    func receive() async throws -> String
    func close() async
}

public protocol WebSocketConnector: Sendable {
    func connect(to url: URL) async throws -> any WebSocketConnection
}

#if canImport(Darwin)
    /// Opens sockets with `URLSessionWebSocketTask`: no cookies, no cache, a 15 second request timeout.
    /// The socket is open once the server's first frame arrives, so callers time the handshake themselves.
    public struct URLSessionWebSocketConnector: WebSocketConnector {
        public init() {}

        public func connect(to url: URL) async throws -> any WebSocketConnection {
            let configuration = URLSessionConfiguration.ephemeral
            configuration.httpCookieStorage = nil
            configuration.httpShouldSetCookies = false
            configuration.urlCache = nil
            configuration.timeoutIntervalForRequest = 15
            let session = URLSession(configuration: configuration)
            let task = session.webSocketTask(with: url)
            task.resume()
            return URLSessionWebSocketConnection(session: session, task: task)
        }
    }

    private final class URLSessionWebSocketConnection: WebSocketConnection, @unchecked Sendable {
        private let session: URLSession
        private let task: URLSessionWebSocketTask

        init(session: URLSession, task: URLSessionWebSocketTask) {
            self.session = session
            self.task = task
        }

        func send(_ text: String) async throws {
            try await task.send(.string(text))
        }

        func receive() async throws -> String {
            switch try await task.receive() {
            case .string(let text): return text
            case .data(let data): return String(decoding: data, as: UTF8.self)
            @unknown default: return ""
            }
        }

        func close() async {
            task.cancel(with: .normalClosure, reason: nil)
            session.invalidateAndCancel()
        }
    }
#endif
