import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let sendKey = UUID(uuidString: "11111111-2222-3333-4444-555555555555")!
private let baseURL = URL(string: "https://yak.example.com")!

private func makeClient(_ transport: any HTTPTransport) -> APIClient {
    APIClient(
        baseURL: baseURL, token: "yakdev_abc", transport: transport, appVersion: "1.0.0",
        systemDescription: "27.2; iPhone19,4", sleep: { _ in })
}

private func encodedBody<R>(_ endpoint: Endpoint<R>) throws -> Data {
    guard let body = endpoint.body else { throw URLError(.zeroByteResource) }
    return try JSONEncoder().encode(body)
}

private func jsonBody<R>(_ endpoint: Endpoint<R>) throws -> [String: Any] {
    let data = try encodedBody(endpoint)
    return try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
}

@Test func buildsTheEventsRequest() async throws {
    let transport = ScriptedTransport()
    await transport.on("GET threads/d0e515ef/events", try .scenario(200, "scenario-thread-events"))
    let page = try await makeClient(transport).send(.events("d0e515ef", after: 11, limit: 900))
    #expect(page.latestSequence == 14)
    let url = try #require(await transport.requests.first?.url)
    #expect(url.absoluteString == "https://yak.example.com/api/app/v1/threads/d0e515ef/events?after=11&limit=500")
}

@Test func clampsAfterAndLimit() {
    let endpoint = Endpoint<EventsPage>.events("a", after: -4, limit: 0)
    #expect(endpoint.query == [URLQueryItem(name: "after", value: "0"), URLQueryItem(name: "limit", value: "1")])
}

@Test func escapesIdentifiersInPaths() async throws {
    #expect(Endpoint<ThreadShow>.thread("../me").path == "threads/..%2Fme")
    let transport = ScriptedTransport()
    _ = try? await makeClient(transport).send(.thread("../me"))
    let url = try #require(await transport.requests.first?.url)
    #expect(url.absoluteString == "https://yak.example.com/api/app/v1/threads/..%2Fme")
}

@Test func keepsABasePathOnTheInstanceAddress() async throws {
    let transport = ScriptedTransport()
    let client = APIClient(
        baseURL: URL(string: "https://example.com/yak/")!, token: nil, transport: transport, appVersion: "1",
        systemDescription: "x", sleep: { _ in })
    _ = try? await client.send(.thread("a"))
    #expect(await transport.requests.first?.url?.absoluteString == "https://example.com/yak/api/app/v1/threads/a")
}

@Test func sendsAPlainMessageAsJSON() throws {
    let endpoint = Endpoint<MessageSendResult>.sendMessage(
        "d0e515ef", text: "Hi", model: nil, delivery: .afterTurn, sendKey: sendKey, attachments: [], references: [])
    #expect(endpoint.method == .post)
    #expect(endpoint.multipart == nil)
    let body = try jsonBody(endpoint)
    #expect(body as NSDictionary == ["message": "Hi", "send_key": sendKey.uuidString, "delivery": "after_turn"])
}

@Test func sendsTheEffortNextToTheModel() async throws {
    let json = Endpoint<MessageSendResult>.sendMessage(
        "d0e515ef", text: "Hi", model: "opus", effort: .xhigh, delivery: .steer, sendKey: sendKey, attachments: [],
        references: [])
    let body = try jsonBody(json)
    #expect(body["model"] as? String == "opus" && body["effort"] as? String == "xhigh")
    let file = MultipartBody.Part.file(name: "x", fileName: "a.png", contentType: "image/png", data: Data("A".utf8))
    let multipart = Endpoint<MessageSendResult>.sendMessage(
        "d0e515ef", text: "Hi", model: "opus", effort: .max, delivery: .steer, sendKey: sendKey, attachments: [file],
        references: ["Image #1"])
    let text = String(decoding: try #require(multipart.multipart).encoded(), as: UTF8.self)
    #expect(text.contains("name=\"effort\"") && text.contains("max"))
}

@Test func sendsAttachmentsAsMultipartInOrder() async throws {
    let files: [MultipartBody.Part] = [
        .file(name: "x", fileName: "a.png", contentType: "image/png", data: Data("AAA".utf8)),
        .file(name: "x", fileName: "b.txt", contentType: "text/plain", data: Data("BBB".utf8)),
    ]
    let endpoint = Endpoint<MessageSendResult>.sendMessage(
        "d0e515ef", text: "Hi", model: "m", delivery: .steer, sendKey: sendKey, attachments: files,
        references: ["Image #1", "File #2"])
    let multipart = try #require(endpoint.multipart)
    #expect(multipart.boundary == "yak-\(sendKey.uuidString)")
    #expect(multipart == endpoint.multipart)
    let text = String(decoding: multipart.encoded(), as: UTF8.self)
    let markers = [
        "name=\"message\"", "name=\"model\"", "name=\"send_key\"", "name=\"delivery\"",
        "name=\"attachments[]\"; filename=\"a.png\"", "Image #1", "name=\"attachments[]\"; filename=\"b.txt\"",
        "File #2",
    ]
    var cursor = text.startIndex
    for marker in markers {
        let range = try #require(text.range(of: marker, range: cursor..<text.endIndex), "missing \(marker)")
        cursor = range.upperBound
    }
    #expect(text.hasSuffix("--yak-\(sendKey.uuidString)--\r\n"))
    #expect(text.contains("\r\n\r\nHi\r\n"))

    let transport = ScriptedTransport()
    _ = try? await makeClient(transport).send(endpoint)
    let request = try #require(await transport.requests.first)
    #expect(
        request.value(forHTTPHeaderField: "Content-Type") == "multipart/form-data; boundary=yak-\(sendKey.uuidString)")
    #expect(request.httpBody == multipart.encoded())
}

@Test func multipartNamesCannotBreakOutOfTheirQuotes() {
    let body = MultipartBody(
        parts: [.file(name: "a", fileName: "x\"\r\nInjected: 1.png", contentType: "image/png", data: Data())],
        boundary: "b")
    let text = String(decoding: body.encoded(), as: UTF8.self)
    #expect(text.contains("filename=\"x%22Injected: 1.png\""))
}

@Test func startThreadSendsExtraRepositoriesAsJSON() throws {
    let draft = NewThreadDraft(
        repo: "example/api", mode: "ask", repositories: [.init(repo: "example/web", role: "context")],
        claudeAccountId: 3, model: nil)
    let endpoint = Endpoint<ThreadCreated>.startThread(
        draft, description: "Fix it", sendKey: sendKey, attachments: [], references: [])
    let body = try jsonBody(endpoint)
    #expect(body["repo"] as? String == "example/api")
    #expect(body["description"] as? String == "Fix it")
    #expect(body["claude_account_id"] as? Int == 3)
    #expect(body["model"] == nil)
    #expect(body["send_key"] as? String == sendKey.uuidString)
    let repositories = try #require(body["repositories"] as? [[String: String]])
    #expect(repositories == [["repo": "example/web", "role": "context"]])
}

@Test func startThreadSendsEffort() throws {
    let draft = NewThreadDraft(repo: "example/api", mode: "fix", model: "opus", effort: .max)
    let endpoint = Endpoint<ThreadCreated>.startThread(
        draft, description: "d", sendKey: sendKey, attachments: [], references: [])
    #expect(try jsonBody(endpoint)["effort"] as? String == "max")
    let multipart = Endpoint<ThreadCreated>.startThread(
        draft, description: "d", sendKey: sendKey,
        attachments: [.file(name: "x", fileName: "a.png", contentType: "image/png", data: Data())], references: [])
    let text = String(decoding: try #require(multipart.multipart).encoded(), as: UTF8.self)
    #expect(text.contains("name=\"effort\"\r\n\r\nmax"))
}

@Test func startThreadIndexesExtraRepositoriesInMultipart() throws {
    let draft = NewThreadDraft(
        repo: "example/api", mode: "ask", repositories: [.init(repo: "example/web", role: "context")])
    let endpoint = Endpoint<ThreadCreated>.startThread(
        draft, description: "d", sendKey: sendKey,
        attachments: [.file(name: "x", fileName: "a.png", contentType: "image/png", data: Data())],
        references: ["Image #1"])
    let text = String(decoding: try #require(endpoint.multipart).encoded(), as: UTF8.self)
    #expect(text.contains("name=\"repositories[0][repo]\"\r\n\r\nexample/web"))
    #expect(text.contains("name=\"repositories[0][role]\"\r\n\r\ncontext"))
    #expect(text.contains("name=\"attachment_refs[]\"\r\n\r\nImage #1"))
}

@Test func trimsAndOmitsTheSearch() {
    #expect(
        Endpoint<ThreadList>.threads(filter: .mine, search: "  ").query == [URLQueryItem(name: "filter", value: "mine")]
    )
    #expect(Endpoint<ThreadList>.threads(filter: .mine, search: nil).query.count == 1)
    let long = Endpoint<ThreadList>.threads(filter: .yak, search: String(repeating: "a", count: 300))
    #expect(long.query.last?.value?.count == 100)
    #expect(Endpoint<ThreadList>.threads(filter: .mine, search: " a ").query.last?.value == "a")
}

@Test func escapesPlusAndAmpersandInTheSearch() async throws {
    let transport = ScriptedTransport()
    _ = try? await makeClient(transport).send(.threads(filter: .everyone, search: "c++ & x"))
    let url = try #require(await transport.requests.first?.url)
    #expect(url.absoluteString == "https://yak.example.com/api/app/v1/threads?filter=everyone&search=c%2B%2B%20%26%20x")
}

@Test func readsRetryAfterOnA429() async throws {
    let body = #"{"error":"rate_limited","message":"m"}"#
    func failure(_ header: String) async -> APIFailure? {
        let transport = FakeTransport(.json(429, body, headers: ["Retry-After": header]))
        do {
            _ = try await makeClient(transport).send(.thread("a"))
            return nil
        } catch { return error }
    }
    guard case .server(let error)? = await failure("12") else {
        Issue.record("not a server failure")
        return
    }
    #expect(error.retryAfterSeconds == 12)
    #expect(error.code == "rate_limited")
    for bad in ["abc", "-1", "99999", "1.5", ""] {
        guard case .server(let other)? = await failure(bad) else {
            Issue.record("not a server failure")
            return
        }
        #expect(other.retryAfterSeconds == nil, "\(bad)")
    }
}

@Test func answersAQuestionWithExplicitNulls() throws {
    let endpoint = Endpoint<StatusResponse>.answerQuestion(
        "t", answers: ["q1": QuestionAnswer(choices: ["a"], other: nil)], note: nil)
    let data = try encodedBody(endpoint)
    let text = String(decoding: data, as: UTF8.self)
    #expect(text.contains("\"note\":null"))
    #expect(text.contains("\"other\":null"))
    #expect(text.contains("\"choices\":[\"a\"]"))
}

@Test func updatesAQueuedMessageWithOnlyTheGivenKeys() throws {
    let endpoint = Endpoint<StatusResponse>.updateQueuedMessage("t", uuid: "u", mode: .steer, text: nil)
    #expect(try jsonBody(endpoint) as NSDictionary == ["mode": "steer"])
}

@Test func authorizesAChannelWithSnakeCaseKeys() throws {
    let endpoint = Endpoint<ChannelAuthorization>.authorizeChannel(socketID: "1.2", channelName: "private-thread.t")
    #expect(try jsonBody(endpoint) as NSDictionary == ["socket_id": "1.2", "channel_name": "private-thread.t"])
}

private struct Row: Sendable, CustomTestStringConvertible {
    let name: String
    let method: Endpoint<EmptyResponse>.Method
    let path: String
    let query: [URLQueryItem]
    var testDescription: String { name }

    init<R>(_ name: String, _ endpoint: Endpoint<R>) {
        self.name = name
        method = Endpoint<EmptyResponse>.Method(rawValue: endpoint.method.rawValue)!
        path = endpoint.path
        query = endpoint.query
    }
}

private let rows: [Row] = [
    Row("threads", Endpoint<ThreadList>.threads(filter: .mine, search: nil)),
    Row("thread", Endpoint<ThreadShow>.thread("t")),
    Row("markSeen", Endpoint<EmptyResponse>.markSeen("t")),
    Row("newThreadOptions", Endpoint<NewThreadOptions>.newThreadOptions()),
    Row(
        "startThread",
        Endpoint<ThreadCreated>.startThread(
            NewThreadDraft(repo: "r", mode: "m"), description: "d", sendKey: sendKey, attachments: [], references: [])),
    Row("repositoryAccess", Endpoint<RepositoryAccess>.repositoryAccess(repo: "r")),
    Row("events", Endpoint<EventsPage>.events("t", after: 0, limit: 10)),
    Row("event", Endpoint<ThreadEvent>.event("t", sequence: 7)),
    Row(
        "sendMessage",
        Endpoint<MessageSendResult>.sendMessage(
            "t", text: "x", model: nil, delivery: .steer, sendKey: sendKey, attachments: [], references: [])),
    Row("updateQueuedMessage", Endpoint<StatusResponse>.updateQueuedMessage("t", uuid: "u", mode: nil, text: "x")),
    Row("withdrawQueuedMessage", Endpoint<EmptyResponse>.withdrawQueuedMessage("t", uuid: "u")),
    Row("answerQuestion", Endpoint<StatusResponse>.answerQuestion("t", answers: [:], note: nil)),
    Row("stopTurn", Endpoint<StatusResponse>.stopTurn("t")),
    Row("continueTurn", Endpoint<StatusResponse>.continueTurn("t")),
    Row("retryRestore", Endpoint<StatusResponse>.retryRestore("t")),
    Row("authorizeChannel", Endpoint<ChannelAuthorization>.authorizeChannel(socketID: "s", channelName: "c")),
]

private let expected: [String: (Endpoint<EmptyResponse>.Method, String)] = [
    "threads": (.get, "threads"),
    "thread": (.get, "threads/t"),
    "markSeen": (.post, "threads/t/seen"),
    "newThreadOptions": (.get, "threads/new"),
    "startThread": (.post, "threads"),
    "repositoryAccess": (.get, "threads/new/repository-access"),
    "events": (.get, "threads/t/events"),
    "event": (.get, "threads/t/events/7"),
    "sendMessage": (.post, "threads/t/messages"),
    "updateQueuedMessage": (.patch, "threads/t/queued-messages/u"),
    "withdrawQueuedMessage": (.delete, "threads/t/queued-messages/u"),
    "answerQuestion": (.post, "threads/t/clarification-answers"),
    "stopTurn": (.post, "threads/t/stop"),
    "continueTurn": (.post, "threads/t/continue"),
    "retryRestore": (.post, "threads/t/restore/retry"),
    "authorizeChannel": (.post, "broadcasting/auth"),
]

@Test(arguments: rows) private func usesTheDocumentedMethodAndPath(_ row: Row) throws {
    let want = try #require(expected[row.name])
    #expect(row.method == want.0)
    #expect(row.path == want.1)
}
