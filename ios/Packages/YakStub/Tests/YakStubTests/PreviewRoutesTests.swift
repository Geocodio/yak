import Foundation
import Testing

@testable import YakStub

private final class NoRedirect: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
    func urlSession(
        _ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void
    ) { completionHandler(nil) }
}

@Suite struct PreviewRoutesTests {
    private static let apiBase = "http://127.0.0.1:9"
    private static let threadID = "d0e515ef"

    private func makePreview() -> StubPreview {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "Packages" { directory.deleteLastPathComponent() }
        let scenarios = directory.appendingPathComponent("YakKit/Tests/YakKitTests/Scenarios")
        return StubPreview { name in
            try? Data(contentsOf: scenarios.appendingPathComponent("\(name).json"))
        }
    }

    private func api(_ method: String, _ route: String, _ body: String = "") -> StubRequest {
        StubRequest(method: method, path: Scenario.apiPrefix + route, body: Data(body.utf8))
    }

    private func status(_ preview: StubPreview, _ id: String = threadID) throws -> [String: Any] {
        let reply = try #require(preview.reply(to: api("GET", "threads/\(id)/preview/status")))
        guard case .reply(let code, _, let body) = reply.answer else { throw StubFailure() }
        #expect(code == 200)
        return try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
    }

    private struct StubFailure: Error {}

    private func warm(_ preview: StubPreview) {
        _ = preview.reply(to: api("POST", "threads/\(Self.threadID)/preview/warm"))
    }

    private func startPages(_ preview: StubPreview) async throws -> (StubPreviewPages, UInt16) {
        let pages = StubPreviewPages(preview: preview, apiBaseURL: Self.apiBase)
        return (pages, try await pages.start())
    }

    private func get(_ url: String, cookie: String? = nil) async throws -> (HTTPURLResponse, String) {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.httpCookieStorage = nil
        configuration.timeoutIntervalForRequest = 5
        let session = URLSession(configuration: configuration, delegate: NoRedirect(), delegateQueue: nil)
        var request = URLRequest(url: URL(string: url)!)
        if let cookie { request.setValue(cookie, forHTTPHeaderField: "Cookie") }
        let (data, response) = try await session.data(for: request)
        return (response as! HTTPURLResponse, String(decoding: data, as: UTF8.self))
    }

    private func ticketURL(_ preview: StubPreview, path: String) throws -> String {
        let reply = try #require(
            preview.reply(to: api("POST", "threads/\(Self.threadID)/preview/ticket", "{\"path\":\"\(path)\"}")))
        guard case .reply(_, _, let body) = reply.answer,
            let object = try JSONSerialization.jsonObject(with: body) as? [String: Any],
            let url = object["url"] as? String
        else { throw StubFailure() }
        return url
    }

    @Test func startingTurnsReadyAfterTwoWarms() async throws {
        let preview = makePreview()
        let (pages, port) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.starting)
        #expect(try status(preview)["state"] as? String == "starting_dev_server")
        warm(preview)
        #expect(try status(preview)["state"] as? String == "starting_dev_server")
        warm(preview)
        let ready = try status(preview)
        #expect(ready["state"] as? String == "ready")
        #expect(ready["url"] as? String == "http://127.0.0.1:\(port)/")
    }

    @Test func aTicketSignsInOnceAndSetsTheCookie() async throws {
        let preview = makePreview()
        let (pages, _) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.ready)
        let url = try ticketURL(preview, path: "/pricing")
        #expect(url.contains("ticket=stub-ticket-1"))
        #expect(url.hasSuffix("to=%2Fpricing"))
        let (first, _) = try await get(url)
        #expect(first.statusCode == 302)
        #expect(first.value(forHTTPHeaderField: "Location") == "/pricing")
        #expect(
            first.value(forHTTPHeaderField: "Set-Cookie")
                == "yak_preview_stub=\(preview.cookieValue); Path=/; HttpOnly; SameSite=Lax")
        let (second, text) = try await get(url)
        #expect(second.statusCode == 401)
        #expect(text == "This preview link has expired. Reload the page to sign in again.")
    }

    @Test func aPageWithoutTheCookieBounces() async throws {
        let preview = makePreview()
        let (pages, port) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.ready)
        let (response, _) = try await get("http://127.0.0.1:\(port)/pricing")
        #expect(response.statusCode == 302)
        #expect(
            response.value(forHTTPHeaderField: "Location")
                == "\(Self.apiBase)/deployments/auth-bounce?to=http%3A%2F%2F127.0.0.1%3A\(port)%2Fpricing")
    }

    @Test func aNewPlayMakesTheCookieStale() async throws {
        let preview = makePreview()
        let (pages, port) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.ready)
        let cookie = "yak_preview_stub=\(preview.cookieValue)"
        let (page, text) = try await get("http://127.0.0.1:\(port)/pricing?a=1&b=2", cookie: cookie)
        #expect(page.statusCode == 200)
        #expect(text.contains("<title>Example preview</title>"))
        #expect(text.contains("Path: /pricing?a=1&amp;b=2"))
        preview.begin(.ready)
        let (stale, _) = try await get("http://127.0.0.1:\(port)/pricing", cookie: cookie)
        #expect(stale.statusCode == 302)
    }

    @Test func restoreWakesThenReady() throws {
        let preview = makePreview()
        preview.begin(.destroyed)
        #expect(try status(preview)["state"] as? String == "destroyed")
        let reply = try #require(preview.reply(to: api("POST", "threads/\(Self.threadID)/preview/restore")))
        guard case .reply(let code, _, _) = reply.answer else { throw StubFailure() }
        #expect(code == 202)
        #expect(try status(preview)["state"] as? String == "waking_sandbox")
        #expect(try status(preview)["state"] as? String == "waking_sandbox")
        #expect(try status(preview)["state"] as? String == "ready")
    }

    @Test func aFixRequestStartsTheFix() throws {
        let preview = makePreview()
        preview.begin(.failing)
        #expect((try status(preview)["setup"] as? [String: Any])?["isRunning"] as? Bool == false)
        preview.messageArrived(threadID: Self.threadID, intent: "fix_preview")
        let fixing = try status(preview)
        #expect(fixing["state"] as? String == "failing")
        #expect((fixing["setup"] as? [String: Any])?["isRunning"] as? Bool == true)
    }

    @Test func aLateRestoreFailureReportsStarting() throws {
        let preview = makePreview()
        preview.begin(.restoreFailed)
        let answer = try status(preview)
        #expect(answer["state"] as? String == "starting_dev_server")
        #expect(answer["url"] is NSNull)
    }

    @Test func signInRefusedRefusesEveryTicket() async throws {
        let preview = makePreview()
        let (pages, _) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.signInRefused)
        let (response, _) = try await get(try ticketURL(preview, path: "/"))
        #expect(response.statusCode == 401)
        #expect(preview.log.last == "auth refused")
    }

    @Test func theLogRecordsTheFlow() async throws {
        let preview = makePreview()
        let (pages, port) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.ready)
        _ = try status(preview)
        warm(preview)
        let (redirect, _) = try await get(try ticketURL(preview, path: "/pricing"))
        let cookie = try #require(redirect.value(forHTTPHeaderField: "Set-Cookie")).split(separator: ";")[0]
        _ = try await get("http://127.0.0.1:\(port)/pricing", cookie: String(cookie))
        #expect(preview.log == ["status ready", "warm", "ticket /pricing", "auth /pricing", "page /pricing"])
    }

    @Test func pagesDownDrops() async throws {
        let preview = makePreview()
        let (pages, port) = try await startPages(preview)
        defer { pages.stop() }
        preview.begin(.pageDown)
        await #expect(throws: (any Error).self) { _ = try await get("http://127.0.0.1:\(port)/") }
    }

    @Test func otherRoutesAreNotPreviewRoutes() throws {
        let preview = makePreview()
        preview.begin(.ready)
        #expect(preview.reply(to: api("GET", "threads/\(Self.threadID)")) == nil)
        #expect(preview.reply(to: api("GET", "me")) == nil)
    }

    @Test func aMissingScenarioFileFailsLoudly() throws {
        let preview = StubPreview { _ in nil }
        preview.begin(.ready)
        let reply = try #require(preview.reply(to: api("GET", "threads/\(Self.threadID)/preview/status")))
        guard case .reply(let code, _, let body) = reply.answer else { throw StubFailure() }
        #expect(code == 500)
        #expect(String(decoding: body, as: UTF8.self).contains("Scenario data missing."))
    }

    @Test func everyPlayHasItsScenarioData() throws {
        let preview = makePreview()
        for play in StubPreviewPlay.allCases {
            preview.begin(play)
            let answer = try status(preview)
            #expect(answer["state"] is String, "\(play)")
        }
        preview.begin(.slow)
        let reply = try #require(preview.reply(to: api("GET", "threads/\(Self.threadID)/preview/status")))
        #expect(reply.delay == .seconds(14))
    }
}
