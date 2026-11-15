import Foundation

@testable import YakKit

/// A terminal socket that plays queued frames and records what the app sent.
actor FakeTerminalSocket: TerminalSocket {
    private(set) var sentBytes: [Data] = []
    private(set) var sentTexts: [String] = []
    private(set) var isClosed = false
    /// The code the connection ended with; a close from the app is 1000.
    var closeCode: Int? { closure?.code }
    private var inbox: [TerminalFrame] = []
    private var closure: TerminalSocketClosed?
    private var receiver: CheckedContinuation<TerminalFrame, any Error>?

    func send(bytes: Data) async throws {
        if let closure { throw closure }
        sentBytes.append(bytes)
    }

    func send(text: String) async throws {
        if let closure { throw closure }
        sentTexts.append(text)
    }

    func receive() async throws -> TerminalFrame {
        if !inbox.isEmpty { return inbox.removeFirst() }
        if let closure { throw closure }
        return try await withCheckedThrowingContinuation { receiver = $0 }
    }

    func close() async {
        isClosed = true
        closeWith(code: 1000)
    }

    func serverSends(_ frame: TerminalFrame) {
        if let waiting = receiver {
            receiver = nil
            waiting.resume(returning: frame)
        } else {
            inbox.append(frame)
        }
    }

    /// Frames already queued are delivered first, then `receive` throws `TerminalSocketClosed(code:)`.
    func closeWith(code: Int?) {
        guard closure == nil else { return }
        closure = TerminalSocketClosed(code: code)
        if let waiting = receiver {
            receiver = nil
            waiting.resume(throwing: TerminalSocketClosed(code: code))
        }
    }
}
