import Foundation

/// What a multipart body held: the text fields by name, in order, and one summary per file part.
struct MultipartSummary: Equatable, Sendable, Codable {
    struct FileSummary: Equatable, Sendable, Codable {
        let name: String
        let fileName: String
        let contentType: String
        let byteCount: Int
    }

    let fields: [String: [String]]
    let files: [FileSummary]
}

/// Reads a `multipart/form-data` body strictly, so a hostile body cannot make the stub allocate or loop without bound.
enum MultipartParser {
    static let bodyLimit = 67_108_864
    static let partLimit = 64
    static let headerLimit = 8192

    private static let lineBreak = Data("\r\n".utf8)
    private static let headerEnd = Data("\r\n\r\n".utf8)

    /// `nil` for anything that is not a bounded, well-formed body: too large, too many parts, a part header above
    /// 8 KB, a missing closing boundary, a part without a name, or no boundary in the content type.
    static func parse(_ body: Data, contentType: String) -> MultipartSummary? {
        guard body.count <= bodyLimit, let boundary = boundary(in: contentType) else { return nil }
        let opening = Data("--\(boundary)".utf8)
        let separator = Data("\r\n--\(boundary)".utf8)
        guard body.starts(with: opening) else { return nil }
        var position = body.startIndex + opening.count
        var fields: [String: [String]] = [:]
        var files: [MultipartSummary.FileSummary] = []
        var partCount = 0
        while true {
            if body[position...].starts(with: Data("--".utf8)) { break }
            guard body[position...].starts(with: lineBreak) else { return nil }
            position += lineBreak.count
            partCount += 1
            guard partCount <= partLimit,
                let headEnd = body.range(
                    of: headerEnd, in: position..<min(body.endIndex, position + headerLimit + headerEnd.count)),
                let (name, fileName, contentType) = disposition(of: body[position..<headEnd.lowerBound])
            else { return nil }
            let contentStart = headEnd.upperBound
            guard let next = body.range(of: separator, in: contentStart..<body.endIndex) else { return nil }
            let content = body[contentStart..<next.lowerBound]
            if let fileName {
                files.append(.init(name: name, fileName: fileName, contentType: contentType, byteCount: content.count))
            } else {
                fields[name, default: []].append(String(decoding: content, as: UTF8.self))
            }
            position = next.upperBound
            guard position + 2 <= body.endIndex else { return nil }
        }
        return MultipartSummary(fields: fields, files: files)
    }

    private static func boundary(in contentType: String) -> String? {
        let pieces = contentType.split(separator: ";").map { $0.trimmingCharacters(in: .whitespaces) }
        guard pieces.first?.lowercased() == "multipart/form-data" else { return nil }
        for piece in pieces.dropFirst() where piece.lowercased().hasPrefix("boundary=") {
            let value = piece.dropFirst("boundary=".count).trimmingCharacters(in: CharacterSet(charactersIn: "\""))
            return value.isEmpty ? nil : value
        }
        return nil
    }

    /// The part's name, its file name when it is a file, and its content type.
    private static func disposition(of head: Data) -> (String, String?, String)? {
        var name: String?
        var fileName: String?
        var contentType = "application/octet-stream"
        for line in String(decoding: head, as: UTF8.self).components(separatedBy: "\r\n") {
            guard let colon = line.firstIndex(of: ":") else { continue }
            let key = line[..<colon].lowercased()
            let value = line[line.index(after: colon)...].trimmingCharacters(in: .whitespaces)
            if key == "content-type" { contentType = value }
            guard key == "content-disposition" else { continue }
            for parameter in value.split(separator: ";").map({ $0.trimmingCharacters(in: .whitespaces) }) {
                guard let equals = parameter.firstIndex(of: "=") else { continue }
                let parameterName = parameter[..<equals].lowercased()
                let parameterValue = parameter[parameter.index(after: equals)...].trimmingCharacters(
                    in: CharacterSet(charactersIn: "\""))
                if parameterName == "name" { name = parameterValue }
                if parameterName == "filename" { fileName = parameterValue }
            }
        }
        guard let name else { return nil }
        return (name, fileName, contentType)
    }
}

/// How much of a request the reader takes before it answers 413.
enum StubRequestLimits {
    static let plain = 1_000_000
    /// For a `Content-Type` that starts with `multipart/form-data`.
    static var multipart: Int { MultipartParser.bodyLimit }

    static func limit(contentType: String?) -> Int {
        contentType?.lowercased().hasPrefix("multipart/form-data") == true ? multipart : plain
    }
}

/// The stub's attachment pieces: multipart sends that note what arrived, signed media links, and the scenarios that
/// make a send fail, a send slow or a link expire. State lives in a shared box, so copies of the value share it.
struct StubAttachments: Sendable {
    static let name = "attachments"
    static let scenarioNames = ["chat-attachments", "upload-fails", "upload-slow", "expired-link"]
    static let threadID = "d0e515ef"
    static let slowDelay = Duration.seconds(10)
    private static let mediaPrefix = "/app-media/task-attachments/"
    private static let scenarioHosts = ["https://yak.example.com"]

    private final class State: @unchecked Sendable {
        let lock = NSLock()
        var summary: MultipartSummary?
        var sendKeysSeen: Set<String> = []
        var mediaIDsSeen: Set<String> = []
        var failNext = false
        var online = true
    }

    private let state = State()
    private let scenarioData: @Sendable (String) -> Data?

    /// `scenarioData` returns the hand-written scenario file with that name (without `.json`), when there is one.
    init(scenarioData: @escaping @Sendable (String) -> Data? = { _ in nil }) {
        self.scenarioData = scenarioData
    }

    /// The last multipart body the stub read, or nil before the first.
    var lastSummary: MultipartSummary? { state.lock.withLock { state.summary } }

    /// Follows the stub's online switch: while offline the media route drops its connections.
    func setOnline(_ online: Bool) { state.lock.withLock { state.online = online } }

    /// A new scenario forgets what the last one saw.
    func begin() {
        state.lock.withLock {
            state.summary = nil
            state.sendKeysSeen = []
            state.mediaIDsSeen = []
            state.failNext = false
        }
    }

    /// The reply for a request under an attachment scenario, or nil when the request is not this feature's.
    func reply(to request: StubRequest, scenario: String, baseURL: String) -> StubReply? {
        guard Self.scenarioNames.contains(scenario) else { return nil }
        let route = "\(request.method) \(request.path)"
        switch route {
        case "GET /__stub/attachments":
            guard let summary = lastSummary, let data = try? JSONEncoder().encode(summary) else {
                return StubReply(answer: Scenario.error(404, "not_found", "No upload has arrived."))
            }
            return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
        case "POST /__stub/attachments/fail-next":
            state.lock.withLock { state.failNext = true }
            return StubReply(answer: .reply(status: 204, contentType: "text/plain", body: Data()))
        default: break
        }
        if request.method == "GET", request.path.hasPrefix(Self.mediaPrefix) {
            return media(request, scenario: scenario)
        }
        let api = Scenario.apiPrefix
        guard request.path.hasPrefix(api) else { return nil }
        let apiRoute = request.path.dropFirst(api.count).split(separator: "/").map(String.init)
        if request.method == "GET", apiRoute == ["threads", Self.threadID], scenario == "chat-attachments" {
            return show(baseURL: baseURL)
        }
        let isSend = request.method == "POST" && (apiRoute == ["threads"] || isMessagesRoute(apiRoute))
        guard isSend, let contentType = request.headers["content-type"],
            contentType.lowercased().hasPrefix("multipart/form-data")
        else { return nil }
        return send(request, contentType: contentType, scenario: scenario, createsThread: apiRoute == ["threads"])
    }

    private func isMessagesRoute(_ route: [String]) -> Bool {
        route.count == 3 && route[0] == "threads" && route[2] == "messages"
    }

    private func send(_ request: StubRequest, contentType: String, scenario: String, createsThread: Bool) -> StubReply {
        guard let summary = MultipartParser.parse(request.body, contentType: contentType) else {
            return StubReply(answer: Scenario.error(422, "invalid_body", "The upload could not be read."))
        }
        let key = summary.fields["send_key"]?.first
        let dropped = state.lock.withLock { () -> Bool in
            state.summary = summary
            var drop = state.failNext
            state.failNext = false
            if let key {
                let firstTime = state.sendKeysSeen.insert(key).inserted
                if scenario == "upload-fails" && firstTime { drop = true }
            }
            return drop
        }
        if dropped { return StubReply(answer: .drop) }
        let object: [String: Any] =
            createsThread ? ["id": Self.threadID] : ["status": "sent", "uuid": key ?? UUID().uuidString.lowercased()]
        let body = (try? JSONSerialization.data(withJSONObject: object)) ?? Data()
        var reply = StubReply(
            answer: .reply(status: createsThread ? 201 : 200, contentType: "application/json", body: body))
        if scenario == "upload-slow" { reply.delay = Self.slowDelay }
        return reply
    }

    private func media(_ request: StubRequest, scenario: String) -> StubReply {
        let identifier = String(request.path.dropFirst(Self.mediaPrefix.count))
        let isOnline = state.lock.withLock { state.online }
        guard isOnline else { return StubReply(answer: .drop) }
        let firstTime = state.lock.withLock { state.mediaIDsSeen.insert(identifier).inserted }
        guard request.query["signature"] != nil, request.query["expires"] != nil,
            !(scenario == "expired-link" && firstTime)
        else {
            return StubReply(answer: Scenario.error(403, "link_expired", "This link has expired."))
        }
        guard let number = Int(identifier) else {
            return StubReply(answer: Scenario.error(404, "not_found", "Not found."))
        }
        let isPDF = number == 42
        return StubReply(
            answer: .reply(
                status: 200, contentType: isPDF ? "application/pdf" : "image/png",
                body: isPDF ? StubMedia.pdf() : StubMedia.png(seed: number)))
    }

    /// The scenario's thread show with its media links pointing at the stub.
    private func show(baseURL: String) -> StubReply {
        guard let data = scenarioData("scenario-thread-attachments") else {
            return StubReply(answer: Scenario.error(404, "not_found", "No scenario data."))
        }
        var text = String(decoding: data, as: UTF8.self)
        for host in Self.scenarioHosts { text = text.replacingOccurrences(of: host, with: baseURL) }
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: Data(text.utf8)))
    }
}

/// Small generated files for the media route: a solid-color PNG and a one-page PDF.
enum StubMedia {
    static func png(seed: Int) -> Data {
        let width = 64
        let height = 48
        let color: [UInt8] = [
            UInt8(truncatingIfNeeded: 60 + seed * 37), UInt8(truncatingIfNeeded: 120 + seed * 17), 200,
        ]
        var raw = Data()
        for _ in 0..<height {
            raw.append(0)
            for _ in 0..<width { raw.append(contentsOf: color) }
        }
        var zlib = Data([0x78, 0x01])
        var offset = 0
        while offset < raw.count {
            let size = min(65535, raw.count - offset)
            let isLast = offset + size == raw.count
            zlib.append(isLast ? 1 : 0)
            zlib.append(contentsOf: [
                UInt8(size & 0xFF), UInt8(size >> 8), UInt8(~size & 0xFF), UInt8((~size >> 8) & 0xFF),
            ])
            zlib.append(raw[offset..<offset + size])
            offset += size
        }
        zlib.append(contentsOf: bigEndian(adler32(raw)))
        var header = Data()
        header.append(contentsOf: bigEndian(UInt32(width)))
        header.append(contentsOf: bigEndian(UInt32(height)))
        header.append(contentsOf: [8, 2, 0, 0, 0])
        return Data([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]) + chunk("IHDR", header) + chunk("IDAT", zlib)
            + chunk("IEND", Data())
    }

    static func pdf() -> Data {
        let stream = "BT /F1 18 Tf 36 96 Td (Example report) Tj ET"
        let objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 240 140] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            "<< /Length \(stream.utf8.count) >>\nstream\n\(stream)\nendstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        ]
        var text = "%PDF-1.4\n"
        var offsets: [Int] = []
        for (index, object) in objects.enumerated() {
            offsets.append(text.utf8.count)
            text += "\(index + 1) 0 obj\n\(object)\nendobj\n"
        }
        let xref = text.utf8.count
        text += "xref\n0 \(objects.count + 1)\n0000000000 65535 f \n"
        for offset in offsets { text += String(format: "%010d 00000 n \n", offset) }
        text += "trailer\n<< /Size \(objects.count + 1) /Root 1 0 R >>\nstartxref\n\(xref)\n%%EOF\n"
        return Data(text.utf8)
    }

    private static func chunk(_ type: String, _ data: Data) -> Data {
        let body = Data(type.utf8) + data
        return Data(bigEndian(UInt32(data.count))) + body + Data(bigEndian(crc32(body)))
    }

    private static func bigEndian(_ value: UInt32) -> [UInt8] {
        [UInt8(value >> 24), UInt8((value >> 16) & 0xFF), UInt8((value >> 8) & 0xFF), UInt8(value & 0xFF)]
    }

    private static func adler32(_ data: Data) -> UInt32 {
        var low: UInt32 = 1
        var high: UInt32 = 0
        for byte in data {
            low = (low + UInt32(byte)) % 65521
            high = (high + low) % 65521
        }
        return high << 16 | low
    }

    private static func crc32(_ data: Data) -> UInt32 {
        var crc: UInt32 = 0xFFFF_FFFF
        for byte in data {
            crc ^= UInt32(byte)
            for _ in 0..<8 { crc = crc & 1 == 1 ? (crc >> 1) ^ 0xEDB8_8320 : crc >> 1 }
        }
        return ~crc
    }
}
