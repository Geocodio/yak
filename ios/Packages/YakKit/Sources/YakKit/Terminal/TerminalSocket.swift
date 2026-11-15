import Foundation

public enum TerminalFrame: Equatable, Sendable {
    /// A binary frame: terminal bytes.
    case output(Data)
    /// A text frame: a bridge message.
    case control(String)
}

public struct TerminalSocketClosed: Error, Equatable, Sendable {
    /// The WebSocket close code; nil when the connection dropped without one.
    public let code: Int?

    public init(code: Int?) { self.code = code }
}

/// One open terminal WebSocket: binary frames for terminal bytes, text frames for the bridge's messages.
public protocol TerminalSocket: Sendable {
    func send(bytes: Data) async throws
    func send(text: String) async throws
    /// Throws `TerminalSocketClosed` when the socket closes.
    func receive() async throws -> TerminalFrame
    func close() async
}

public protocol TerminalSocketConnector: Sendable {
    /// Opens `url` with the `Origin` header. Throws `TerminalSocketClosed(code: nil)` when the handshake
    /// does not finish within `handshakeTimeout`.
    func connect(to url: URL, origin: String, handshakeTimeout: Duration) async throws -> any TerminalSocket
}

#if canImport(Darwin)
    /// Opens sockets with `URLSessionWebSocketTask`: an ephemeral session (no cookies, no cache) and the
    /// `Origin` header on the upgrade request. The socket is returned once the upgrade has finished.
    public struct URLSessionTerminalSocketConnector: TerminalSocketConnector {
        public init() {}

        public func connect(to url: URL, origin: String, handshakeTimeout: Duration) async throws -> any TerminalSocket {
            let configuration = URLSessionConfiguration.ephemeral
            configuration.httpCookieStorage = nil
            configuration.httpShouldSetCookies = false
            configuration.urlCache = nil
            let delegate = TerminalSocketDelegate()
            let session = URLSession(configuration: configuration, delegate: delegate, delegateQueue: nil)
            var request = URLRequest(url: url)
            request.setValue(origin, forHTTPHeaderField: "Origin")
            let task = session.webSocketTask(with: request)
            delegate.task = task
            task.resume()
            let timeout = Task {
                try await Task.sleep(for: handshakeTimeout)
                delegate.finishOpening(opened: false)
            }
            let opened = await withTaskCancellationHandler {
                await delegate.waitUntilOpened()
            } onCancel: {
                delegate.finishOpening(opened: false)
            }
            timeout.cancel()
            guard opened else {
                task.cancel(with: .goingAway, reason: nil)
                session.invalidateAndCancel()
                throw TerminalSocketClosed(code: nil)
            }
            return URLSessionTerminalSocket(session: session, task: task, delegate: delegate)
        }
    }

    /// Records the upgrade result and the close code the server sent.
    private final class TerminalSocketDelegate: NSObject, URLSessionWebSocketDelegate, @unchecked Sendable {
        private let lock = NSLock()
        private var opened: Bool?
        private var waiter: CheckedContinuation<Bool, Never>?
        private var closeCode: Int?
        private var closeWaiters: [CheckedContinuation<Int?, Never>] = []
        private var isFinished = false
        var task: URLSessionWebSocketTask?

        func waitUntilOpened() async -> Bool {
            await withCheckedContinuation { continuation in
                lock.lock()
                if let opened {
                    lock.unlock()
                    continuation.resume(returning: opened)
                } else {
                    waiter = continuation
                    lock.unlock()
                }
            }
        }

        func finishOpening(opened: Bool) {
            lock.lock()
            guard self.opened == nil else {
                lock.unlock()
                return
            }
            self.opened = opened
            let continuation = waiter
            waiter = nil
            lock.unlock()
            continuation?.resume(returning: opened)
        }

        /// The close code once the connection is over, or nil when it ended without one.
        func finalCloseCode() async -> Int? {
            await withCheckedContinuation { continuation in
                lock.lock()
                if isFinished {
                    let code = closeCode
                    lock.unlock()
                    continuation.resume(returning: code)
                } else {
                    closeWaiters.append(continuation)
                    lock.unlock()
                }
            }
        }

        private func finish(code: Int?) {
            lock.lock()
            if closeCode == nil { closeCode = code }
            isFinished = true
            let waiters = closeWaiters
            closeWaiters = []
            let result = closeCode
            lock.unlock()
            for continuation in waiters { continuation.resume(returning: result) }
        }

        func urlSession(
            _ session: URLSession, webSocketTask: URLSessionWebSocketTask, didOpenWithProtocol protocol: String?
        ) {
            finishOpening(opened: true)
        }

        func urlSession(
            _ session: URLSession, webSocketTask: URLSessionWebSocketTask,
            didCloseWith closeCode: URLSessionWebSocketTask.CloseCode, reason: Data?
        ) {
            finish(code: closeCode == .invalid ? nil : closeCode.rawValue)
        }

        func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: (any Error)?) {
            finishOpening(opened: false)
            finish(code: nil)
        }
    }

    private final class URLSessionTerminalSocket: TerminalSocket, @unchecked Sendable {
        private let session: URLSession
        private let task: URLSessionWebSocketTask
        private let delegate: TerminalSocketDelegate

        init(session: URLSession, task: URLSessionWebSocketTask, delegate: TerminalSocketDelegate) {
            self.session = session
            self.task = task
            self.delegate = delegate
        }

        func send(bytes: Data) async throws {
            try await sending(.data(bytes))
        }

        func send(text: String) async throws {
            try await sending(.string(text))
        }

        private func sending(_ message: URLSessionWebSocketTask.Message) async throws {
            do {
                try await task.send(message)
            } catch {
                throw TerminalSocketClosed(code: await delegate.finalCloseCode())
            }
        }

        func receive() async throws -> TerminalFrame {
            do {
                switch try await task.receive() {
                case .data(let data): return .output(data)
                case .string(let text): return .control(text)
                @unknown default: return .control("")
                }
            } catch {
                throw TerminalSocketClosed(code: await delegate.finalCloseCode())
            }
        }

        func close() async {
            task.cancel(with: .normalClosure, reason: nil)
            session.invalidateAndCancel()
        }
    }
#endif
