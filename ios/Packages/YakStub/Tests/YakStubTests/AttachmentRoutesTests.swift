import Foundation
import Network
import Testing

@testable import YakStub

@Suite struct AttachmentRoutesTests {
    private static let boundary = "test-boundary"
    private static let multipartType = "multipart/form-data; boundary=\(boundary)"
    private static let base = "http://127.0.0.1:9"

    private enum Part {
        case field(String, String)
        case file(String, String, String, Data)
    }

    /// The bytes the app's multipart writer produces for the same parts.
    private func body(_ parts: [Part], boundary: String = boundary, closing: Bool = true) -> Data {
        var data = Data()
        for part in parts {
            data.append(Data("--\(boundary)\r\n".utf8))
            switch part {
            case .field(let name, let value):
                data.append(Data("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n\(value)\r\n".utf8))
            case .file(let name, let fileName, let type, let bytes):
                data.append(
                    Data(
                        "Content-Disposition: form-data; name=\"\(name)\"; filename=\"\(fileName)\"\r\nContent-Type: \(type)\r\n\r\n"
                            .utf8))
                data.append(bytes)
                data.append(Data("\r\n".utf8))
            }
        }
        if closing { data.append(Data("--\(boundary)--\r\n".utf8)) }
        return data
    }

    private func scenarioData(_ name: String) -> Data? {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "Packages" { directory.deleteLastPathComponent() }
        return try? Data(
            contentsOf: directory.appendingPathComponent("YakKit/Tests/YakKitTests/Scenarios/\(name).json"))
    }

    private func attachments(_ scenario: String) -> StubAttachments {
        let feature = StubAttachments { [self] in scenarioData($0) }
        feature.begin()
        return feature
    }

    private func send(
        _ feature: StubAttachments, _ scenario: String, key: String, route: String = "threads/d0e515ef/messages"
    )
        -> StubReply?
    {
        let parts: [Part] = [
            .field("message", "Hi"), .field("send_key", key),
            .file("attachments[]", "a.png", "image/png", Data(count: 10)),
        ]
        return feature.reply(
            to: StubRequest(
                method: "POST", path: Scenario.apiPrefix + route, headers: ["content-type": Self.multipartType],
                body: body(parts)), scenario: scenario, baseURL: Self.base)
    }

    private func media(_ feature: StubAttachments, _ scenario: String, _ id: Int, signed: Bool = true) -> StubReply? {
        feature.reply(
            to: StubRequest(
                method: "GET", path: "/app-media/task-attachments/\(id)",
                query: signed ? ["expires": "1", "signature": "a"] : [:]), scenario: scenario, baseURL: Self.base)
    }

    private func status(_ reply: StubReply?) -> Int? {
        guard case .reply(let code, _, _)? = reply?.answer else { return nil }
        return code
    }

    @Test func parsesWhatTheAppWrites() throws {
        let data = body([
            .field("message", "Hello"), .field("send_key", "key-1"),
            .file("attachments[]", "a.png", "image/png", Data(count: 100)),
            .file("attachments[]", "b \"x\".pdf", "application/pdf", Data(count: 7)),
        ])
        let summary = try #require(MultipartParser.parse(data, contentType: Self.multipartType))
        #expect(summary.fields == ["message": ["Hello"], "send_key": ["key-1"]])
        #expect(
            summary.files == [
                .init(name: "attachments[]", fileName: "a.png", contentType: "image/png", byteCount: 100),
                .init(name: "attachments[]", fileName: "b \"x\".pdf", contentType: "application/pdf", byteCount: 7),
            ])
    }

    @Test func hostileBodiesAreRefused() {
        let type = Self.multipartType
        #expect(MultipartParser.parse(Data(count: MultipartParser.bodyLimit + 1), contentType: type) == nil)
        let manyParts = body((0..<65).map { .field("f\($0)", "v") })
        #expect(MultipartParser.parse(manyParts, contentType: type) == nil)
        let atTheLimit = body((0..<64).map { .field("f\($0)", "v") })
        #expect(MultipartParser.parse(atTheLimit, contentType: type) != nil)
        let bigHeader = Data(
            "--\(Self.boundary)\r\nContent-Disposition: form-data; name=\"x\"\r\nX-Pad: \(String(repeating: "a", count: 9000))\r\n\r\nv\r\n--\(Self.boundary)--\r\n"
                .utf8)
        #expect(MultipartParser.parse(bigHeader, contentType: type) == nil)
        #expect(MultipartParser.parse(body([.field("a", "b")], closing: false), contentType: type) == nil)
        #expect(MultipartParser.parse(Data("garbage".utf8), contentType: type) == nil)
        #expect(MultipartParser.parse(body([.field("a", "b")]), contentType: "application/json") == nil)
        #expect(MultipartParser.parse(body([.field("a", "b")]), contentType: "multipart/form-data") == nil)
    }

    @Test func mediaNeedsASignature() {
        let feature = attachments("chat-attachments")
        #expect(status(media(feature, "chat-attachments", 41, signed: false)) == 403)
        #expect(status(media(feature, "chat-attachments", 41)) == 200)
        guard case .reply(_, let type, let png)? = media(feature, "chat-attachments", 41)?.answer else {
            Issue.record("no reply")
            return
        }
        #expect(type == "image/png")
        #expect(png.starts(with: [0x89, 0x50, 0x4E, 0x47]))
        guard case .reply(_, let pdfType, let pdf)? = media(feature, "chat-attachments", 42)?.answer else {
            Issue.record("no reply")
            return
        }
        #expect(pdfType == "application/pdf")
        #expect(pdf.starts(with: Data("%PDF-".utf8)))
    }

    @Test func theFirstSendFailsInUploadFails() {
        let feature = attachments("upload-fails")
        #expect(send(feature, "upload-fails", key: "k1")?.answer == .drop)
        #expect(status(send(feature, "upload-fails", key: "k1")) == 200)
        #expect(send(feature, "upload-fails", key: "k2")?.answer == .drop)
        #expect(feature.lastSummary?.fields["send_key"] == ["k2"])
    }

    @Test func aNewThreadAnswersCreated() {
        let feature = attachments("chat-attachments")
        #expect(status(send(feature, "chat-attachments", key: "k1", route: "threads")) == 201)
    }

    @Test func failNextDropsOneSend() {
        let feature = attachments("chat-attachments")
        let fail = StubRequest(method: "POST", path: "/__stub/attachments/fail-next")
        #expect(status(feature.reply(to: fail, scenario: "chat-attachments", baseURL: Self.base)) == 204)
        #expect(send(feature, "chat-attachments", key: "k1")?.answer == .drop)
        #expect(status(send(feature, "chat-attachments", key: "k2")) == 200)
    }

    @Test func uploadSlowAnswersAfterTenSeconds() {
        let feature = attachments("upload-slow")
        #expect(send(feature, "upload-slow", key: "k1")?.delay == .seconds(10))
    }

    @Test func expiredLinkAnswers403Once() {
        let feature = attachments("expired-link")
        #expect(status(media(feature, "expired-link", 41)) == 403)
        #expect(status(media(feature, "expired-link", 41)) == 200)
        #expect(status(media(feature, "expired-link", 42)) == 403)
        #expect(status(media(feature, "expired-link", 42)) == 200)
    }

    @Test func mediaIsUnreachableOffline() {
        let feature = attachments("chat-attachments")
        feature.setOnline(false)
        #expect(media(feature, "chat-attachments", 41)?.answer == .drop)
        feature.setOnline(true)
        #expect(status(media(feature, "chat-attachments", 41)) == 200)
    }

    @Test func otherScenariosAndRoutesAreNotHandled() {
        let feature = attachments("chat-attachments")
        #expect(media(feature, "paired", 41) == nil)
        let other = StubRequest(method: "GET", path: Scenario.apiPrefix + "me")
        #expect(feature.reply(to: other, scenario: "chat-attachments", baseURL: Self.base) == nil)
    }

    @Test func theShowLinksPointAtTheStub() throws {
        let feature = attachments("chat-attachments")
        let request = StubRequest(method: "GET", path: Scenario.apiPrefix + "threads/d0e515ef")
        guard
            case .reply(200, _, let data)? = feature.reply(
                to: request, scenario: "chat-attachments", baseURL: "http://127.0.0.1:5")?.answer
        else {
            Issue.record("no show")
            return
        }
        let text = String(decoding: data, as: UTF8.self)
        #expect(text.contains("http://127.0.0.1:5/app-media/task-attachments/41?"))
        #expect(!text.contains("https://yak.example.com"))
    }

    // MARK: Against a running server

    private func startServer() async throws -> UInt16 {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(directory: directory.appendingPathComponent("fixtures"))
        let scenarios = directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
        let server = try StubServer(port: 0, fixtures: fixtures, scenario: .paired, scenarios: scenarios)
        let port = try await server.start()
        _ = try await request("POST", port, "/__stub/scenario", body: Data("chat-attachments".utf8))
        return port
    }

    private func request(
        _ method: String, _ port: UInt16, _ path: String, body: Data? = nil, contentType: String? = nil
    ) async throws -> (Int, Data) {
        var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
        request.httpMethod = method
        request.httpBody = body
        if let contentType { request.setValue(contentType, forHTTPHeaderField: "Content-Type") }
        let (data, response) = try await URLSession.shared.data(for: request)
        return ((response as! HTTPURLResponse).statusCode, data)
    }

    /// Sends raw bytes and returns whatever the server writes before it closes.
    private func raw(_ port: UInt16, _ text: String) async -> String {
        let connection = NWConnection(host: "127.0.0.1", port: NWEndpoint.Port(rawValue: port)!, using: .tcp)
        connection.start(queue: .global())
        connection.send(content: Data(text.utf8), completion: .contentProcessed { _ in })
        var received = Data()
        while true {
            let chunk: Data? = await withCheckedContinuation { continuation in
                connection.receive(minimumIncompleteLength: 1, maximumLength: 4096) { data, _, _, error in
                    continuation.resume(returning: error == nil ? data : nil)
                }
            }
            guard let chunk, !chunk.isEmpty else { break }
            received += chunk
        }
        connection.cancel()
        return String(decoding: received, as: UTF8.self)
    }

    @Test func aLargeMultipartBodyIsReadWhole() async throws {
        let port = try await startServer()
        let data = body([
            .field("message", "Big"), .field("send_key", "big-1"),
            .file("attachments[]", "big.bin", "application/octet-stream", Data(count: 5_000_000)),
        ])
        let sent = try await request(
            "POST", port, "/api/app/v1/threads/d0e515ef/messages", body: data, contentType: Self.multipartType)
        #expect(sent.0 == 200)
        let summary = try JSONDecoder().decode(
            MultipartSummary.self, from: try await request("GET", port, "/__stub/attachments").1)
        #expect(summary.files.map(\.byteCount) == [5_000_000])
    }

    @Test func aMultipartBodyAboveTheLimitIsRefused() async throws {
        let port = try await startServer()
        let answer = await raw(
            port,
            "POST /api/app/v1/threads HTTP/1.1\r\nHost: x\r\nContent-Type: \(Self.multipartType)\r\nContent-Length: 68157440\r\n\r\n"
        )
        #expect(answer.hasPrefix("HTTP/1.1 413"))
    }

    @Test func aPlainBodyKeepsItsOneMegabyteLimit() async throws {
        let port = try await startServer()
        let answer = await raw(
            port,
            "POST /api/app/v1/threads HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: 2000000\r\n\r\n"
        )
        #expect(answer.hasPrefix("HTTP/1.1 413"))
    }

    @Test func theQueryReachesTheRequest() async throws {
        let target = StubServer.parseTarget("/app-media/task-attachments/41?expires=1&signature=a")
        #expect(target.path == "/app-media/task-attachments/41")
        #expect(target.query == ["expires": "1", "signature": "a"])
        let port = try await startServer()
        #expect(try await request("GET", port, "/app-media/task-attachments/41?expires=1&signature=a").0 == 200)
        #expect(try await request("GET", port, "/app-media/task-attachments/41").0 == 403)
    }

    @Test func offlineDropsTheMediaRouteThroughTheServer() async throws {
        let port = try await startServer()
        _ = try await request("POST", port, "/__stub/online", body: Data("no".utf8))
        await #expect(throws: (any Error).self) {
            try await request("GET", port, "/app-media/task-attachments/41?expires=1&signature=a")
        }
        _ = try await request("POST", port, "/__stub/online", body: Data("yes".utf8))
        #expect(try await request("GET", port, "/app-media/task-attachments/41?expires=1&signature=a").0 == 200)
    }
}
