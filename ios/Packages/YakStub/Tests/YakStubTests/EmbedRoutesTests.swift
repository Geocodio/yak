import Foundation
import Testing

@testable import YakStub

@Suite struct EmbedRoutesTests {
    private let thread = "d0e515ef"
    private let bearer = ["authorization": "Bearer stub-token"]

    private func context(_ scenario: String, me: String = #"{"web":{"embed":1},"user":{}}"#) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1:5555",
            fixtures: try StubFixtures(directory: directory.appendingPathComponent("fixtures")),
            threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: Data(me.utf8)) })
    }

    private func parts(_ reply: StubReply?) throws -> (status: Int, body: String, headers: [String: String]) {
        let reply = try #require(reply)
        guard case .reply(let status, _, let body) = reply.answer else {
            Issue.record("dropped")
            return (0, "", [:])
        }
        return (status, String(decoding: body, as: UTF8.self), reply.headers)
    }

    private func mint(
        _ feature: StubEmbed, _ scenario: String = "embed-basic", path: String? = nil, headers: [String: String]? = nil
    ) throws -> (status: Int, body: String, headers: [String: String]) {
        let path = path ?? "/app/embed/threads/\(thread)"
        let request = StubRequest(
            method: "POST", path: "/api/app/v1/web-session", headers: headers ?? bearer,
            body: Data(#"{"path":"\#(path)"}"#.utf8))
        return try parts(feature.answer(route: "POST web-session", request: request, context: context(scenario)))
    }

    private func code(from body: String) throws -> String {
        let object = try #require(try JSONSerialization.jsonObject(with: Data(body.utf8)) as? [String: Any])
        return try #require(object["code"] as? String)
    }

    private func redeem(_ feature: StubEmbed, code: String, scenario: String = "embed-basic") throws -> (
        status: Int, body: String, headers: [String: String]
    ) {
        let request = StubRequest(
            method: "POST", path: StubEmbed.redeemPath,
            headers: ["content-type": "application/x-www-form-urlencoded"], body: Data("code=\(code)".utf8))
        return try parts(feature.web(request, context: context(scenario)))
    }

    private func load(
        _ feature: StubEmbed, cookie: String?, id: String? = nil, scenario: String = "embed-basic"
    ) throws -> (status: Int, body: String, headers: [String: String]) {
        let request = StubRequest(
            method: "GET", path: StubEmbed.threadPrefix + (id ?? thread),
            headers: cookie.map { ["cookie": "other=1; \(StubEmbed.cookieName)=\($0)"] } ?? [:])
        return try parts(feature.web(request, context: context(scenario)))
    }

    private func cookie(from headers: [String: String]) throws -> String {
        let value = try #require(headers["Set-Cookie"])
        return String(try #require(value.split(separator: ";").first).dropFirst(StubEmbed.cookieName.count + 1))
    }

    private func signedIn(_ feature: StubEmbed, scenario: String = "embed-basic") throws -> String {
        let minted = try mint(feature, scenario)
        return try cookie(from: redeem(feature, code: code(from: minted.body), scenario: scenario).headers)
    }

    @Test func theMintAnswersACodeAndARedeemAddressOnTheStubsOwnHost() throws {
        let answer = try mint(StubEmbed())
        #expect(answer.status == 201)
        let object = try #require(try JSONSerialization.jsonObject(with: Data(answer.body.utf8)) as? [String: Any])
        #expect(object["redeemUrl"] as? String == "http://127.0.0.1:5555/app/embed/session")
        #expect((object["code"] as? String)?.hasPrefix("yakweb_") == true)
        #expect((object["code"] as? String)?.count == 7 + 48)
        #expect(object["expiresAt"] is String)
    }

    @Test func theMintNeedsABearerTokenAndAnEmbedThreadPath() throws {
        let feature = StubEmbed()
        #expect(try mint(feature, headers: [:]).status == 401)
        #expect(try mint(feature, path: "/threads/\(thread)").status == 422)
        #expect(try mint(feature, path: "/app/embed/threads/short").status == 422)
        #expect(try mint(feature, path: "/app/embed/threads/\(thread)/../x").status == 422)
    }

    @Test func theUnsupportedScenarioHasNoMintAndMeWithoutWeb() throws {
        let feature = StubEmbed()
        #expect(try mint(feature, "embed-unsupported").status == 404)
        let request = StubRequest(method: "GET", path: "/api/app/v1/me", headers: bearer)
        let me = try parts(
            feature.answer(route: "GET me", request: request, context: context("embed-unsupported")))
        #expect(me.status == 200 && !me.body.contains("\"web\"") && me.body.contains("user"))
        #expect(feature.answer(route: "GET me", request: request, context: try context("embed-basic")) == nil)
    }

    @Test func theFailingScenarioRefusesEveryMint() throws {
        #expect(try mint(StubEmbed(), "embed-failed").status == 503)
    }

    @Test func theServerTakesWebOutOfAnyFeaturesMeUnlessTheScenarioOffersIt() {
        let me = StubReply(
            answer: .reply(
                status: 200, contentType: "application/json", body: Data(#"{"web":{"embed":1},"user":{}}"#.utf8)))
        func body(_ scenario: String) -> String {
            guard case .reply(_, _, let data) = StubEmbed.meReply(me, scenario: scenario).answer else { return "" }
            return String(decoding: data, as: UTF8.self)
        }
        #expect(!body("paired").contains("web") && body("paired").contains("user"))
        #expect(!body("embed-unsupported").contains("web"))
        #expect(body("embed-basic").contains("web"))
        #expect(body("embed-slow").contains("web"))
    }

    @Test func onlyTheEmbedScenariosPutWebInMe() throws {
        let feature = StubEmbed()
        let request = StubRequest(method: "GET", path: "/api/app/v1/me", headers: bearer)
        for scenario in ["paired", "question-open", "slow"] {
            let me = try parts(feature.answer(route: "GET me", request: request, context: context(scenario)))
            #expect(!me.body.contains("\"web\""), Comment(rawValue: scenario))
        }
        for scenario in ["embed-basic", "embed-expired-once", "embed-slow"] {
            #expect(
                feature.answer(route: "GET me", request: request, context: try context(scenario)) == nil,
                Comment(rawValue: scenario))
        }
    }

    @Test func aCodeIsRedeemedOnceAndSetsACookieThenRedirectsToItsPath() throws {
        let feature = StubEmbed()
        let minted = try code(from: mint(feature).body)
        let first = try redeem(feature, code: minted)
        #expect(first.status == 303 && first.headers["Location"] == "/app/embed/threads/\(thread)")
        #expect(first.headers["Set-Cookie"]?.contains("HttpOnly") == true)
        #expect(first.headers["Location"]?.contains(minted) == false)
        #expect(try redeem(feature, code: minted).status == 403)
        #expect(try redeem(feature, code: "yakweb_unknown").status == 403)
        #expect(try redeem(feature, code: "").status == 403)
    }

    @Test func theThreadPageNeedsTheSessionCookie() throws {
        let feature = StubEmbed()
        let session = try signedIn(feature)
        let page = try load(feature, cookie: session)
        #expect(page.status == 200 && page.body.contains(#"var threadId = "d0e515ef""#))
        #expect(try load(feature, cookie: nil).status == 401)
        #expect(try load(feature, cookie: "wrong").status == 401)
    }

    @Test func thePageSpeaksTheBridge() throws {
        let feature = StubEmbed()
        let page = try load(feature, cookie: signedIn(feature)).body
        for fragment in [
            "window.webkit.messageHandlers", #"type: "ready""#, #"type: "link""#, #"type: "openPane""#,
            #"type: "cardAnswered""#, #"type: "auth""#, #"type: "unsentAction""#, #"type: "threadChanged""#,
            #"case "open":"#, #"case "outbox":"#, #"case "focus":"#, #"case "refresh":"#, #"case "scrollToBottom":"#,
            #"case "connectivity":"#, "window.__yakEmbed", "data-testid",
        ] {
            #expect(page.contains(fragment), "\(fragment)")
        }
    }

    @Test func aPageForABadThreadIdIsNotFound() throws {
        let feature = StubEmbed()
        let session = try signedIn(feature)
        #expect(try load(feature, cookie: session, id: "short").status == 404)
        #expect(try load(feature, cookie: session, id: "x\"</script>").status == 404)
    }

    @Test func theExpiredOnceScenarioAnswersTheFirstLoadWith401AndThenWorks() throws {
        let feature = StubEmbed()
        let session = try signedIn(feature, scenario: "embed-expired-once")
        #expect(try load(feature, cookie: session, scenario: "embed-expired-once").status == 401)
        let again = try signedIn(feature, scenario: "embed-expired-once")
        #expect(try load(feature, cookie: again, scenario: "embed-expired-once").status == 200)
        feature.reset()
        let afterReset = try signedIn(feature, scenario: "embed-expired-once")
        #expect(try load(feature, cookie: afterReset, scenario: "embed-expired-once").status == 401)
    }

    @Test func theSlowScenarioPostsReadyAfterTwelveSeconds() throws {
        let feature = StubEmbed()
        let slow = try load(feature, cookie: signedIn(feature, scenario: "embed-slow"), scenario: "embed-slow")
        #expect(slow.body.contains("setTimeout(sendReady, 12000)"))
        let basic = try load(feature, cookie: signedIn(feature))
        #expect(basic.body.contains("setTimeout(sendReady, 0)"))
        #expect(StubEmbed.slowReadyDelay == .seconds(12))
    }

    @Test func theControlRoutesExpireTheNextLoadAndShowTheCounts() throws {
        let feature = StubEmbed()
        let session = try signedIn(feature)
        _ = try load(feature, cookie: session)
        let state = try parts(feature.control(StubRequest(method: "GET", path: "/__stub/embed/state")))
        #expect(state.body == "mints=1 redeems=1 loads=1")
        _ = feature.control(StubRequest(method: "POST", path: "/__stub/embed/expire"))
        #expect(try load(feature, cookie: session).status == 401)
        #expect(try load(feature, cookie: session).status == 200)
    }

    @Test func offlineDropsThePageRequests() throws {
        let feature = StubEmbed()
        let session = try signedIn(feature)
        feature.setOnline(false)
        let request = StubRequest(
            method: "GET", path: StubEmbed.threadPrefix + thread,
            headers: ["cookie": "\(StubEmbed.cookieName)=\(session)"])
        #expect(try #require(feature.web(request, context: context("embed-basic"))).answer == .drop)
        feature.setOnline(true)
        #expect(try load(feature, cookie: session).status == 200)
    }

    @Test func otherPathsAndMethodsAreNotThisFeaturesAndTheScenariosAreItsOwn() throws {
        let feature = StubEmbed()
        let other = StubRequest(method: "GET", path: "/app/embed/other")
        #expect(feature.web(other, context: try context("embed-basic")) == nil)
        #expect(feature.coreScenario(for: "embed-slow") == "paired")
        #expect(feature.scenarios == ["embed-basic", "embed-expired-once", "embed-slow", "embed-unsupported"])
    }

    @Test func theEmbedFeatureIsRegistered() {
        #expect(StubFeatures.all.contains { $0.name == "embed" })
        let owners = StubFeatures.all.filter { $0.scenarios.contains("embed-basic") }
        #expect(owners.count == 1)
    }
}

/// Keeps the 303 of the redeem as the answer, so the test reads its cookie and location.
private final class StopAtRedirect: NSObject, URLSessionTaskDelegate {
    func urlSession(
        _ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void
    ) {
        completionHandler(nil)
    }
}

@Suite struct EmbedServerTests {
    @Test func theWholeHandOffWorksOverHTTP() async throws {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(directory: directory.appendingPathComponent("fixtures"))
        // An own feature, because the shared one also hears the other tests' servers go offline.
        let server = try StubServer(port: 0, fixtures: fixtures, scenario: .paired, features: [StubEmbed()])
        let port = try await server.start()
        let base = "http://127.0.0.1:\(port)"
        let configuration = URLSessionConfiguration.ephemeral
        let session = URLSession(configuration: configuration)

        var scenario = URLRequest(url: URL(string: base + "/__stub/scenario")!)
        scenario.httpMethod = "POST"
        scenario.httpBody = Data("embed-basic".utf8)
        _ = try await session.data(for: scenario)

        var mint = URLRequest(url: URL(string: base + "/api/app/v1/web-session")!)
        mint.httpMethod = "POST"
        mint.setValue("Bearer stub-token", forHTTPHeaderField: "Authorization")
        mint.httpBody = Data(#"{"path":"/app/embed/threads/d0e515ef"}"#.utf8)
        let (minted, mintResponse) = try await session.data(for: mint)
        #expect((mintResponse as? HTTPURLResponse)?.statusCode == 201)
        let object = try #require(try JSONSerialization.jsonObject(with: minted) as? [String: Any])
        let code = try #require(object["code"] as? String)
        #expect(object["redeemUrl"] as? String == base + "/app/embed/session")

        var redeem = URLRequest(url: URL(string: base + "/app/embed/session")!)
        redeem.httpMethod = "POST"
        redeem.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        redeem.httpBody = Data("code=\(code)".utf8)
        let (_, redeemResponse) = try await session.data(for: redeem, delegate: StopAtRedirect())
        let redirect = try #require(redeemResponse as? HTTPURLResponse)
        #expect(
            redirect.statusCode == 303
                && redirect.value(forHTTPHeaderField: "Location") == "/app/embed/threads/d0e515ef")
        let setCookie = try #require(redirect.value(forHTTPHeaderField: "Set-Cookie"))
        let cookie = String(try #require(setCookie.split(separator: ";").first))

        func get(_ path: String) async throws -> (Int, String) {
            var request = URLRequest(url: URL(string: base + path)!)
            request.setValue(cookie, forHTTPHeaderField: "Cookie")
            let (data, response) = try await session.data(for: request)
            return ((response as! HTTPURLResponse).statusCode, String(decoding: data, as: UTF8.self))
        }
        let page = try await get("/app/embed/threads/d0e515ef")
        #expect(page.0 == 200 && page.1.contains("window.__yakEmbed"))
        // The cookie opens another thread's page too, and the code does not work twice.
        #expect(try await get("/app/embed/threads/a1b2c3d4").0 == 200)
        let (_, again) = try await session.data(for: redeem, delegate: StopAtRedirect())
        #expect((again as? HTTPURLResponse)?.statusCode == 403)
        server.stop()
    }
}
