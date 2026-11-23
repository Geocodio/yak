import Foundation
import Testing

@testable import YakStub

@Suite struct EmbedFileRoutesTests {
    private let id = "01HZX3K9ABCDEFGHJKMNPQRSTV"

    private func context() throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return StubContext(
            scenario: "embed-basic", baseURL: "http://127.0.0.1:5555",
            fixtures: try StubFixtures(directory: directory.appendingPathComponent("fixtures")),
            threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: Data("{}".utf8)) })
    }

    private func parts(_ reply: StubReply?) throws -> (status: Int, body: String, headers: [String: String]) {
        let reply = try #require(reply)
        guard case .reply(let status, _, let body) = reply.answer else {
            Issue.record("dropped")
            return (0, "", [:])
        }
        return (status, String(decoding: body, as: UTF8.self), reply.headers)
    }

    private func signedInCookie(_ feature: StubEmbed) throws -> String {
        let mint = StubRequest(
            method: "POST", path: "/api/app/v1/web-session", headers: ["authorization": "Bearer stub-token"],
            body: Data(#"{"path":"/app/embed/threads/d0e515ef"}"#.utf8))
        let minted = try parts(feature.answer(route: "POST web-session", request: mint, context: context()))
        let object = try #require(try JSONSerialization.jsonObject(with: Data(minted.body.utf8)) as? [String: Any])
        let code = try #require(object["code"] as? String)
        let redeem = StubRequest(
            method: "POST", path: StubEmbed.redeemPath,
            headers: ["content-type": "application/x-www-form-urlencoded", "user-agent": "Mozilla/5.0 YakApp/1.0"],
            body: Data("code=\(code)".utf8))
        let redeemed = try parts(feature.web(redeem, context: context()))
        let value = try #require(redeemed.headers["Set-Cookie"])
        return String(try #require(value.split(separator: ";").first).dropFirst(StubEmbed.cookieName.count + 1))
    }

    private func get(_ feature: StubEmbed, _ path: String, cookie: String?) throws -> (
        status: Int, body: String, headers: [String: String]
    ) {
        let headers = cookie.map { ["cookie": "\(StubEmbed.cookieName)=\($0)"] } ?? [:]
        return try parts(feature.web(StubRequest(method: "GET", path: path, headers: headers), context: context()))
    }

    @Test func aFilePageWithoutASessionRedirectsToTheLoginPageLikeTheRealServer() throws {
        let feature = StubEmbed()
        for path in ["/files/\(id)", "/files/\(id)/raw"] {
            let answer = try get(feature, path, cookie: nil)
            #expect(answer.status == 302, "\(path)")
            #expect(answer.headers["Location"] == "/login")
        }
        #expect(try get(feature, "/files/\(id)", cookie: "not-a-session").status == 302)
    }

    @Test func theEmbedThreadPageStillAnswers401WithoutASession() throws {
        let answer = try get(StubEmbed(), "/app/embed/threads/d0e515ef", cookie: nil)
        #expect(answer.status == 401)
    }

    @Test func theLoginPageIsAPlainPage() throws {
        let answer = try get(StubEmbed(), "/login", cookie: nil)
        #expect(answer.status == 200)
        #expect(answer.body.contains("Sign in"))
    }

    @Test func aSignedInFileIsTheViewerPageAndItsFramedReport() throws {
        let feature = StubEmbed()
        let cookie = try signedInCookie(feature)
        let page = try get(feature, "/files/\(id)", cookie: cookie)
        #expect(page.status == 200)
        #expect(page.body.contains("<title>Weekly report.html - Yak</title>"))
        #expect(page.body.contains("/files/\(id)/raw?download=1"))
        let raw = try get(feature, "/files/\(id)/raw", cookie: cookie)
        #expect(raw.body.contains("Signups grew by twelve percent this week."))
        #expect(!raw.body.contains("<script"))
    }

    @Test func theScriptedReportClicksALinkOnATimer() throws {
        let feature = StubEmbed()
        let cookie = try signedInCookie(feature)
        let raw = try get(feature, "/files/\(StubEmbed.scriptedFileID)/raw", cookie: cookie)
        #expect(raw.body.contains(".click()"))
        #expect(StubEmbed.scriptedFileID.utf8.count == 26)
    }

    @Test func anExpiryEndsTheSessionOnceAndTheFileModesAnswerAsNamed() throws {
        let feature = StubEmbed()
        let cookie = try signedInCookie(feature)
        _ = feature.control(StubRequest(method: "POST", path: "/__stub/embed/expire"))
        #expect(try get(feature, "/files/\(id)", cookie: cookie).status == 302)
        #expect(try get(feature, "/files/\(id)", cookie: cookie).status == 200)
        for (mode, status) in [("missing", 404), ("forbidden", 403), ("broken", 500), ("attachment", 200), ("ok", 200)]
        {
            _ = feature.control(StubRequest(method: "POST", path: "/__stub/embed/file/\(mode)"))
            #expect(try get(feature, "/files/\(id)", cookie: cookie).status == status, "\(mode)")
        }
    }

    @Test func theAttachmentModeSendsThePageAsADownload() throws {
        let feature = StubEmbed()
        let cookie = try signedInCookie(feature)
        _ = feature.control(StubRequest(method: "POST", path: "/__stub/embed/file/attachment"))
        let answer = try get(feature, "/files/\(id)", cookie: cookie)
        #expect(answer.headers["Content-Disposition"]?.hasPrefix("attachment") == true)
        #expect(answer.body == "Weekly report as text")
    }
}
