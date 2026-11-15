import CryptoKit
import Foundation
import Network

/// A WebSocket listener on the loopback interface, on a free port, playing `scenario-terminal-frames`.
/// It does its own upgrade and framing, because the URL's token, columns and rows are not visible to
/// `NWProtocolWebSocket`'s request handler. It logs event names only, never bytes.
final class FakeBridge: @unchecked Sendable {
    private let frames: Data
    private let origin: String
    private let admit: @Sendable (String) -> BridgeAdmission
    private let queue = DispatchQueue(label: "fake-bridge")
    private let lock = NSLock()
    private var listener: NWListener?
    private var peers: [Peer] = []
    private var opened = 0
    private var resize: (columns: Int, rows: Int)?

    init(frames: Data, origin: String, admit: @escaping @Sendable (String) -> BridgeAdmission) {
        self.frames = frames
        self.origin = origin
        self.admit = admit
    }

    var openedCount: Int { lock.withLock { opened } }
    var lastResize: (columns: Int, rows: Int)? { lock.withLock { resize } }

    func resetCounts() {
        lock.withLock {
            opened = 0
            resize = nil
        }
    }

    /// Starts listening and returns the port.
    func start() async throws -> Int {
        let parameters = NWParameters.tcp
        parameters.requiredInterfaceType = .loopback
        let listener = try NWListener(using: parameters, on: .any)
        lock.withLock { self.listener = listener }
        listener.newConnectionHandler = { [self] connection in
            let peer = Peer(connection: connection, bridge: self)
            lock.withLock { peers.append(peer) }
            connection.start(queue: queue)
            peer.readRequest()
        }
        return try await withCheckedThrowingContinuation { continuation in
            let once = OnceFlag()
            listener.stateUpdateHandler = { [listener] state in
                switch state {
                case .ready:
                    if once.first() { continuation.resume(returning: Int(listener.port?.rawValue ?? 0)) }
                case .failed(let error):
                    if once.first() { continuation.resume(throwing: error) }
                default: break
                }
            }
            listener.start(queue: queue)
        }
    }

    func closeAll(code: Int) {
        let current = lock.withLock { peers }
        for peer in current { peer.close(code: code) }
    }

    func stop() {
        closeAll(code: 1001)
        lock.withLock {
            listener?.cancel()
            listener = nil
        }
    }

    fileprivate func recordOpened() { lock.withLock { opened += 1 } }
    fileprivate func record(columns: Int, rows: Int) { lock.withLock { resize = (columns, rows) } }
    fileprivate func remove(_ peer: Peer) { lock.withLock { peers.removeAll { $0 === peer } } }
    fileprivate func admission(for token: String) -> BridgeAdmission { admit(token) }
    fileprivate var allowedOrigin: String { origin }

    /// The script's `banner`, `prompt` and `responses`.
    fileprivate var script: (banner: String, prompt: String, responses: [String: String]) {
        let object = (try? JSONSerialization.jsonObject(with: frames)) as? [String: Any] ?? [:]
        return (
            object["banner"] as? String ?? "", object["prompt"] as? String ?? "",
            object["responses"] as? [String: String] ?? [:]
        )
    }

    /// One accepted TCP connection.
    fileprivate final class Peer: @unchecked Sendable {
        private let connection: NWConnection
        private let bridge: FakeBridge
        private var buffer = Data()
        private var upgraded = false
        private var line = Data()
        private var finished = false
        private let lock = NSLock()

        init(connection: NWConnection, bridge: FakeBridge) {
            self.connection = connection
            self.bridge = bridge
        }

        func readRequest() { receive() }

        private func receive() {
            connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) { [self] data, _, isComplete, error in
                if let data, !data.isEmpty {
                    buffer += data
                    process()
                }
                if error != nil || isComplete { finish() } else if !isFinished { receive() }
            }
        }

        private var isFinished: Bool { lock.withLock { finished } }

        private func finish() {
            lock.withLock { finished = true }
            connection.cancel()
            bridge.remove(self)
        }

        private func process() {
            if !upgraded {
                guard let end = buffer.range(of: Data("\r\n\r\n".utf8)) else { return }
                let head = String(decoding: buffer[..<end.lowerBound], as: UTF8.self)
                buffer.removeSubrange(..<end.upperBound)
                upgrade(head)
            }
            while upgraded, let frame = nextFrame() { handle(frame) }
        }

        private func upgrade(_ head: String) {
            var lines = head.components(separatedBy: "\r\n")
            let start = lines.removeFirst().split(separator: " ")
            var headers: [String: String] = [:]
            for line in lines {
                guard let colon = line.firstIndex(of: ":") else { continue }
                headers[line[..<colon].lowercased()] = line[line.index(after: colon)...].trimmingCharacters(
                    in: .whitespaces)
            }
            guard start.count >= 2, let key = headers["sec-websocket-key"],
                let components = URLComponents(string: String(start[1])), components.path == "/terminal/ws"
            else {
                send(Data("HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n".utf8)) { self.finish() }
                return
            }
            let query = Dictionary(
                (components.queryItems ?? []).map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { first, _ in first }
            )
            let accept = Data(Insecure.SHA1.hash(data: Data((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").utf8)))
                .base64EncodedString()
            let response =
                "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                + "Sec-WebSocket-Accept: \(accept)\r\n\r\n"
            upgraded = true
            send(Data(response.utf8))
            let admission = bridge.admission(for: query["token"] ?? "")
            guard headers["origin"] == bridge.allowedOrigin, admission != .refuse else {
                log("bridge refused")
                close(code: 4403)
                return
            }
            guard admission == .open else {
                log("bridge too many")
                close(code: 4429)
                return
            }
            if let columns = query["cols"].flatMap(Int.init), let rows = query["rows"].flatMap(Int.init) {
                bridge.record(columns: columns, rows: rows)
            }
            bridge.recordOpened()
            log("bridge open")
            let script = bridge.script
            sendFrame(opcode: 0x2, payload: Data(script.banner.utf8))
            sendFrame(opcode: 0x2, payload: Data(script.prompt.utf8))
        }

        /// One client frame: opcode and unmasked payload. Messages are not fragmented.
        private func nextFrame() -> (opcode: UInt8, payload: Data)? {
            let bytes = [UInt8](buffer)
            guard bytes.count >= 2 else { return nil }
            let opcode = bytes[0] & 0x0F
            let masked = bytes[1] & 0x80 != 0
            var length = Int(bytes[1] & 0x7F)
            var offset = 2
            if length == 126 {
                guard bytes.count >= 4 else { return nil }
                length = Int(bytes[2]) << 8 | Int(bytes[3])
                offset = 4
            } else if length == 127 {
                guard bytes.count >= 10 else { return nil }
                length = bytes[2..<10].reduce(0) { $0 << 8 | Int($1) }
                offset = 10
            }
            let maskLength = masked ? 4 : 0
            guard bytes.count >= offset + maskLength + length else { return nil }
            var payload = Array(bytes[(offset + maskLength)..<(offset + maskLength + length)])
            if masked {
                let mask = bytes[offset..<(offset + 4)]
                let base = mask.startIndex
                for index in payload.indices { payload[index] ^= mask[base + index % 4] }
            }
            buffer.removeSubrange(..<(offset + maskLength + length))
            return (opcode, Data(payload))
        }

        private func handle(_ frame: (opcode: UInt8, payload: Data)) {
            switch frame.opcode {
            case 0x8:
                close(code: 1000)
            case 0x9:
                sendFrame(opcode: 0xA, payload: frame.payload)
            case 0x1:
                guard let object = try? JSONSerialization.jsonObject(with: frame.payload) as? [String: Any],
                    object["type"] as? String == "resize", let columns = object["cols"] as? Int,
                    let rows = object["rows"] as? Int
                else { return }
                bridge.record(columns: columns, rows: rows)
                log("bridge resize")
            case 0x2:
                typed(frame.payload)
            default: break
            }
        }

        /// Echoes the typed bytes, and answers a finished line.
        private func typed(_ payload: Data) {
            var echo = Data()
            var lines: [String] = []
            for byte in payload {
                if byte == 0x0D {
                    echo += Data("\r\n".utf8)
                    lines.append(String(decoding: line, as: UTF8.self))
                    line = Data()
                } else {
                    echo.append(byte)
                    line.append(byte)
                }
            }
            sendFrame(opcode: 0x2, payload: echo)
            let script = bridge.script
            for text in lines {
                if text == "exit" {
                    sendFrame(opcode: 0x1, payload: Data(#"{"type":"exit","code":0}"#.utf8))
                    close(code: 1000)
                    return
                }
                if let response = script.responses[text] { sendFrame(opcode: 0x2, payload: Data(response.utf8)) }
                sendFrame(opcode: 0x2, payload: Data(script.prompt.utf8))
            }
        }

        func close(code: Int) {
            var payload = Data([UInt8(code >> 8 & 0xFF), UInt8(code & 0xFF)])
            payload.append(Data())
            guard upgraded else {
                finish()
                return
            }
            sendFrame(opcode: 0x8, payload: payload) { self.finish() }
        }

        private func sendFrame(opcode: UInt8, payload: Data, then: (@Sendable () -> Void)? = nil) {
            var frame = Data([0x80 | opcode])
            if payload.count < 126 {
                frame.append(UInt8(payload.count))
            } else if payload.count < 65536 {
                frame += [126, UInt8(payload.count >> 8), UInt8(payload.count & 0xFF)]
            } else {
                frame.append(127)
                for shift in stride(from: 56, through: 0, by: -8) { frame.append(UInt8(payload.count >> shift & 0xFF)) }
            }
            send(frame + payload, then: then)
        }

        private func send(_ data: Data, then: (@Sendable () -> Void)? = nil) {
            connection.send(
                content: data,
                completion: .contentProcessed { _ in then?() })
        }
    }
}

private final class OnceFlag: @unchecked Sendable {
    private let lock = NSLock()
    private var done = false
    func first() -> Bool {
        lock.withLock {
            defer { done = true }
            return !done
        }
    }
}
