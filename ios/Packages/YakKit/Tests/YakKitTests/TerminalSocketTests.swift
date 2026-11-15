#if canImport(Network)
    import Foundation
    import Network
    import Testing

    @testable import YakKit

    /// A loopback WebSocket server for the connector. `closeCode` is sent after the upgrade.
    private final class LoopbackBridge: @unchecked Sendable {
        private let lock = NSLock()
        private let queue = DispatchQueue(label: "loopback-bridge")
        private var listener: NWListener
        private var connections: [NWConnection] = []
        private let originRecorder = OriginRecorder()
        private let closeCode: UInt16?
        private let receivedRecorder = ReceivedRecorder()
        private let answersUpgrade: Bool

        init(closeCode: UInt16? = nil, answersUpgrade: Bool = true) throws {
            self.closeCode = closeCode
            self.answersUpgrade = answersUpgrade
            let parameters = NWParameters.tcp
            parameters.requiredInterfaceType = .loopback
            if answersUpgrade {
                let options = NWProtocolWebSocket.Options(.version13)
                let recorder = originRecorder
                options.setClientRequestHandler(queue) { _, headers in
                    let origin = headers.first { $0.name.lowercased() == "origin" }?.value
                    recorder.record(origin ?? "")
                    return NWProtocolWebSocket.Response(status: .accept, subprotocol: nil)
                }
                parameters.defaultProtocolStack.applicationProtocols.insert(options, at: 0)
            }
            listener = try NWListener(using: parameters, on: .any)
        }

        var recordedOrigins: [String] { originRecorder.all }
        var receivedFrames: [ReceivedFrame] { receivedRecorder.all }

        func start() async throws -> UInt16 {
            listener.newConnectionHandler = { [weak self] connection in
                guard let self else { return }
                self.lock.withLock { self.connections.append(connection) }
                connection.start(queue: self.queue)
                self.serve(connection)
            }
            return try await withCheckedThrowingContinuation { continuation in
                let once = Once()
                listener.stateUpdateHandler = { [listener] state in
                    switch state {
                    case .ready:
                        if let port = listener.port?.rawValue, once.first() { continuation.resume(returning: port) }
                    case .failed(let error):
                        if once.first() { continuation.resume(throwing: error) }
                    default: break
                    }
                }
                listener.start(queue: queue)
            }
        }

        func stop() {
            listener.cancel()
            for connection in lock.withLock({ connections }) { connection.cancel() }
        }

        private func serve(_ connection: NWConnection) {
            guard answersUpgrade else { return }
            if let closeCode {
                let metadata = NWProtocolWebSocket.Metadata(opcode: .close)
                metadata.closeCode = closeCode >= 4000 ? .privateCode(closeCode) : .protocolCode(.normalClosure)
                let context = NWConnection.ContentContext(identifier: "close", metadata: [metadata])
                connection.send(content: nil, contentContext: context, isComplete: true, completion: .idempotent)
                return
            }
            receiveAndEcho(connection)
            readFrames(from: connection)
        }

        private func readFrames(from connection: NWConnection) {
            connection.receiveMessage { [weak self] content, context, _, error in
                guard let self, error == nil else { return }
                let metadata = context?.protocolMetadata(definition: NWProtocolWebSocket.definition)
                if let metadata = metadata as? NWProtocolWebSocket.Metadata, let content {
                    self.receivedRecorder.record(ReceivedFrame(isBinary: metadata.opcode == .binary, data: content))
                }
                self.readFrames(from: connection)
            }
        }

        /// Sends a binary and a text frame, then echoes nothing.
        private func receiveAndEcho(_ connection: NWConnection) {
            let binary = NWProtocolWebSocket.Metadata(opcode: .binary)
            connection.send(
                content: Data([0x1b, 0x5b, 0x41]),
                contentContext: NWConnection.ContentContext(identifier: "bin", metadata: [binary]),
                completion: .idempotent)
            let text = NWProtocolWebSocket.Metadata(opcode: .text)
            connection.send(
                content: Data(#"{"type":"exit","code":0}"#.utf8),
                contentContext: NWConnection.ContentContext(identifier: "txt", metadata: [text]),
                completion: .idempotent)
        }
    }

    private struct ReceivedFrame: Equatable {
        let isBinary: Bool
        let data: Data
    }

    private final class ReceivedRecorder: @unchecked Sendable {
        private let lock = NSLock()
        private var frames: [ReceivedFrame] = []
        func record(_ frame: ReceivedFrame) { lock.withLock { frames.append(frame) } }
        var all: [ReceivedFrame] { lock.withLock { frames } }
    }

    private final class OriginRecorder: @unchecked Sendable {
        private let lock = NSLock()
        private var origins: [String] = []
        func record(_ origin: String) { lock.withLock { origins.append(origin) } }
        var all: [String] { lock.withLock { origins } }
    }

    private final class Once: @unchecked Sendable {
        private let lock = NSLock()
        private var done = false
        func first() -> Bool {
            lock.withLock {
                defer { done = true }
                return !done
            }
        }
    }

    @Suite(.timeLimit(.minutes(1)))
    struct TerminalSocketTests {
        private let connector = URLSessionTerminalSocketConnector()

        private func url(_ port: UInt16) -> URL { URL(string: "ws://127.0.0.1:\(port)/terminal/ws?token=t")! }

        @Test func sendsTheOriginOnTheUpgrade() async throws {
            let bridge = try LoopbackBridge()
            let port = try await bridge.start()
            defer { bridge.stop() }
            let socket = try await connector.connect(
                to: url(port), origin: "http://127.0.0.1:\(port)", handshakeTimeout: .seconds(5))
            await socket.close()
            #expect(bridge.recordedOrigins == ["http://127.0.0.1:\(port)"])
        }

        @Test func binaryAndTextFramesGoBothWays() async throws {
            let bridge = try LoopbackBridge()
            let port = try await bridge.start()
            defer { bridge.stop() }
            let socket = try await connector.connect(
                to: url(port), origin: "http://127.0.0.1:\(port)", handshakeTimeout: .seconds(5))
            #expect(try await socket.receive() == .output(Data([0x1b, 0x5b, 0x41])))
            #expect(try await socket.receive() == .control(#"{"type":"exit","code":0}"#))
            try await socket.send(bytes: Data([0x61]))
            try await socket.send(text: BridgeControl.resize(cols: 80, rows: 24))
            for _ in 0..<100 where bridge.receivedFrames.count < 2 { try await Task.sleep(for: .milliseconds(50)) }
            #expect(
                bridge.receivedFrames == [
                    ReceivedFrame(isBinary: true, data: Data([0x61])),
                    ReceivedFrame(isBinary: false, data: Data(BridgeControl.resize(cols: 80, rows: 24).utf8)),
                ])
            await socket.close()
        }

        @Test(arguments: [4403, 4408, 4429, 1000] as [Int])
        func privateCloseCodesReachTheClient(code: Int) async throws {
            let bridge = try LoopbackBridge(closeCode: UInt16(code))
            let port = try await bridge.start()
            defer { bridge.stop() }
            let socket = try await connector.connect(
                to: url(port), origin: "http://127.0.0.1:\(port)", handshakeTimeout: .seconds(5))
            await #expect(throws: TerminalSocketClosed(code: code)) { try await socket.receive() }
            await socket.close()
        }

        @Test func aHandshakeThatNeverFinishesTimesOut() async throws {
            let bridge = try LoopbackBridge(answersUpgrade: false)
            let port = try await bridge.start()
            defer { bridge.stop() }
            await #expect(throws: TerminalSocketClosed(code: nil)) {
                try await connector.connect(
                    to: url(port), origin: "http://127.0.0.1:\(port)", handshakeTimeout: .milliseconds(300))
            }
        }
    }
#endif
