import Foundation
import Network

/// The second loopback listener: the preview host. It serves the auth handshake, a cookie and a plain page, and
/// writes its own small HTTP/1.1 responses so `StubReply` and `StubServer` need no header field.
final class StubPreviewPages: @unchecked Sendable {
    private let preview: StubPreview
    private let apiBaseURL: String
    private let lock = NSLock()
    private var listener: NWListener?

    init(preview: StubPreview, apiBaseURL: String) {
        self.preview = preview
        self.apiBaseURL = apiBaseURL
    }

    /// Starts listening on a free port and returns it; the preview learns the pages base URL from it.
    func start() async throws -> UInt16 {
        let parameters = NWParameters.tcp
        parameters.requiredInterfaceType = .loopback
        let listener = try NWListener(using: parameters, on: .any)
        lock.withLock { self.listener = listener }
        listener.newConnectionHandler = { [self] connection in handle(connection) }
        let port: UInt16 = try await withCheckedThrowingContinuation { continuation in
            let once = PagesOnce()
            listener.stateUpdateHandler = { [listener] state in
                switch state {
                case .ready:
                    if once.first() { continuation.resume(returning: listener.port?.rawValue ?? 0) }
                case .failed(let error):
                    if once.first() { continuation.resume(throwing: error) }
                default: break
                }
            }
            listener.start(queue: .global())
        }
        preview.setPagesBaseURL("http://127.0.0.1:\(port)")
        return port
    }

    func stop() { lock.withLock { listener?.cancel() } }

    private struct Response {
        var status: Int
        var contentType = "text/plain; charset=utf-8"
        var body = ""
        var location: String?
        var cookie: String?
    }

    private func handle(_ connection: NWConnection) {
        connection.start(queue: .global())
        Task {
            guard let request = await Self.readRequest(from: connection), preview.playing != .pageDown else {
                connection.cancel()
                return
            }
            Self.write(respond(to: request), to: connection)
        }
    }

    private func respond(to request: (method: String, target: String, cookie: String?)) -> Response {
        let components = URLComponents(string: "http://host" + request.target)
        let path = components?.path ?? "/"
        if path == "/_yak/auth" {
            let query = components?.queryItems ?? []
            let ticket = query.first { $0.name == "ticket" }?.value ?? ""
            var destination = query.first { $0.name == "to" }?.value ?? "/"
            if !destination.hasPrefix("/") || destination.hasPrefix("//") { destination = "/" }
            guard preview.playing != .signInRefused, preview.redeem(ticket: ticket) else {
                preview.record("auth refused")
                return Response(
                    status: 401, body: "This preview link has expired. Reload the page to sign in again.")
            }
            preview.record("auth \(destination)")
            return Response(
                status: 302, location: destination,
                cookie: "yak_preview_stub=\(preview.cookieValue); Path=/; HttpOnly; SameSite=Lax")
        }
        let cookies = (request.cookie ?? "").split(separator: ";").map { $0.trimmingCharacters(in: .whitespaces) }
        guard cookies.contains("yak_preview_stub=\(preview.cookieValue)") else {
            preview.record("bounce \(path)")
            let page = preview.pagesBaseURL + request.target
            return Response(
                status: 302, location: "\(apiBaseURL)/deployments/auth-bounce?to=\(StubPreview.percentEncoded(page))")
        }
        preview.record("page \(path)")
        let shown = Self.escaped(request.target)
        return Response(
            status: 200, contentType: "text/html; charset=utf-8",
            body: """
                <!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">\
                <title>Example preview</title></head><body><h1>Example preview</h1><p>Path: \(shown)</p>\
                <p><a href="/pricing">Plans</a> <a href="https://example.com/docs">Docs</a></p></body></html>
                """)
    }

    private static func escaped(_ text: String) -> String {
        text.replacingOccurrences(of: "&", with: "&amp;").replacingOccurrences(of: "<", with: "&lt;")
            .replacingOccurrences(of: ">", with: "&gt;").replacingOccurrences(of: "\"", with: "&quot;")
    }

    private static func write(_ response: Response, to connection: NWConnection) {
        let reasons = [200: "OK", 302: "Found", 401: "Unauthorized"]
        let body = Data(response.body.utf8)
        var head = "HTTP/1.1 \(response.status) \(reasons[response.status] ?? "Error")\r\n"
        head += "Content-Type: \(response.contentType)\r\nContent-Length: \(body.count)\r\n"
        if let location = response.location { head += "Location: \(location)\r\n" }
        if let cookie = response.cookie { head += "Set-Cookie: \(cookie)\r\n" }
        head += "Connection: close\r\n\r\n"
        connection.send(
            content: Data(head.utf8) + body, contentContext: .finalMessage, isComplete: true,
            completion: .contentProcessed { _ in connection.cancel() })
    }

    /// Reads the request head; the pages host only answers GET, so no body is read.
    private static func readRequest(from connection: NWConnection) async -> (
        method: String, target: String, cookie: String?
    )? {
        var buffer = Data()
        let separator = Data("\r\n\r\n".utf8)
        while buffer.count < 100_000 {
            if let range = buffer.range(of: separator) {
                let head = String(decoding: buffer[..<range.lowerBound], as: UTF8.self)
                var lines = head.components(separatedBy: "\r\n")
                let start = lines.removeFirst().split(separator: " ")
                guard start.count >= 2 else { return nil }
                let cookie = lines.first { $0.lowercased().hasPrefix("cookie:") }
                    .map { $0.dropFirst("cookie:".count).trimmingCharacters(in: .whitespaces) }
                return (String(start[0]), String(start[1]), cookie)
            }
            let chunk: Data? = await withCheckedContinuation { continuation in
                connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) { data, _, _, error in
                    continuation.resume(returning: error == nil && !(data?.isEmpty ?? true) ? data : nil)
                }
            }
            guard let chunk else { return nil }
            buffer += chunk
        }
        return nil
    }
}

private final class PagesOnce: @unchecked Sendable {
    private let lock = NSLock()
    private var done = false
    func first() -> Bool {
        lock.withLock {
            defer { done = true }
            return !done
        }
    }
}
