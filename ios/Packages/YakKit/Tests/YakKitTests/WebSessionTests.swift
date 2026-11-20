import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let instanceURL = URL(string: "https://yak.example.com")!

private func session(
    redeemUrl: String = "https://yak.example.com/app/embed/session", code: String = "yakweb_AbC123"
) -> WebSession {
    WebSession(redeemUrl: redeemUrl, code: code, expiresAt: "2026-11-20T10:00:00Z")
}

@Test func theMintAnswerDecodes() throws {
    let decoded = try JSONDecoder().decode(WebSession.self, from: Fixtures.data("web-session"))
    #expect(decoded.code == "volatile" && decoded.redeemUrl == "volatile" && decoded.expiresAt == "volatile")
}

@Test func theMintEndpointPostsThePath() async throws {
    let transport = FakeTransport(
        .json(
            201,
            #"{"redeemUrl":"https://yak.example.com/app/embed/session","code":"yakweb_x","expiresAt":"2026-11-20T10:00:00Z"}"#
        ))
    let client = APIClient(
        baseURL: instanceURL, token: "yakdev_abc", transport: transport, appVersion: "1.0.0",
        systemDescription: "27.2; iPhone19,4", sleep: { _ in })
    let result = try await client.send(.webSession(path: "/app/embed/threads/d0e515ef"))
    #expect(result.code == "yakweb_x")
    let request = try #require(await transport.requests.first)
    #expect(request.httpMethod == "POST" && request.url?.path == "/api/app/v1/web-session")
    let body = try #require(request.httpBody)
    #expect(try JSONDecoder().decode([String: String].self, from: body) == ["path": "/app/embed/threads/d0e515ef"])
}

@Test func theCodeIsHiddenInEveryTextForm() {
    let value = session()
    #expect(!String(describing: value).contains("AbC123"))
    #expect(!String(reflecting: value).contains("AbC123"))
    #expect(!"\(value)".contains("AbC123"))
    var dumped = ""
    dump(value, to: &dumped)
    #expect(!dumped.contains("AbC123"))
}

@Test func theCodeCannotBeEncodedForStorage() {
    #expect(!(WebSession.self is any Encodable.Type))
}

@Test func theRedeemRequestIsAFormPost() throws {
    let request = try #require(EmbedRedeem.redeemRequest(for: session(), instanceURL: instanceURL))
    #expect(request.httpMethod == "POST")
    #expect(request.url?.absoluteString == "https://yak.example.com/app/embed/session")
    #expect(request.value(forHTTPHeaderField: "Content-Type") == "application/x-www-form-urlencoded")
    #expect(request.httpBody == Data("code=yakweb_AbC123".utf8))
    #expect(request.url?.query == nil)
}

@Test(arguments: [
    "http://yak.example.com/app/embed/session",
    "https://other.example.com/app/embed/session",
    "https://yak.example.com:8443/app/embed/session",
    "https://yak.example.com/app/embed/session?code=x",
    "https://yak.example.com/app/embed/session#x",
    "https://yak.example.com/other",
    "https://user:pass@yak.example.com/app/embed/session",
    "https://yak.example.com.evil.test/app/embed/session",
    "javascript:alert(1)", "", "/app/embed/session",
])
func aRedeemAddressOffTheInstanceIsRefused(address: String) {
    #expect(EmbedRedeem.redeemRequest(for: session(redeemUrl: address), instanceURL: instanceURL) == nil)
}

@Test func theHostIsComparedWithoutCase() {
    let upper = session(redeemUrl: "https://YAK.example.com/app/embed/session")
    #expect(EmbedRedeem.redeemRequest(for: upper, instanceURL: instanceURL) != nil)
}

@Test func loopbackHTTPNeedsTheFlagAndTheSamePort() {
    let local = URL(string: "http://127.0.0.1:8080")!
    let value = session(redeemUrl: "http://127.0.0.1:8080/app/embed/session")
    #expect(EmbedRedeem.redeemRequest(for: value, instanceURL: local) == nil)
    #expect(EmbedRedeem.redeemRequest(for: value, instanceURL: local, allowsLoopbackHTTP: true) != nil)
    let otherPort = session(redeemUrl: "http://127.0.0.1:9090/app/embed/session")
    #expect(EmbedRedeem.redeemRequest(for: otherPort, instanceURL: local, allowsLoopbackHTTP: true) == nil)
    let otherHost = session(redeemUrl: "http://192.168.1.2:8080/app/embed/session")
    #expect(EmbedRedeem.redeemRequest(for: otherHost, instanceURL: local, allowsLoopbackHTTP: true) == nil)
}

@Test(arguments: ["", "a b", "x&code=y", "x=y", "yak\nweb", "é", String(repeating: "a", count: 129)])
func aCodeThatCouldChangeTheFormIsRefused(code: String) {
    #expect(EmbedRedeem.redeemRequest(for: session(code: code), instanceURL: instanceURL) == nil)
}

@Test(arguments: [("d0e515ef", true), ("A1b2C3d4", true), ("d0e515e", false), ("d0e515efg", false), ("d0e5/5ef", false),
    ("../../x", false), ("", false), ("d0e515é", false)])
func theThreadPathNeedsAnEightCharacterID(id: String, isValid: Bool) {
    #expect((EmbedRedeem.threadPath(id) != nil) == isValid)
    if isValid { #expect(EmbedRedeem.threadPath(id) == "/app/embed/threads/\(id)") }
}
