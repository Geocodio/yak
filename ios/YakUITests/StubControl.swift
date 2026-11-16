import XCTest

/// Talks to the running `yak-stub`. The `ui_smoke` lane starts it and passes its port as
/// `TEST_RUNNER_YAK_STUB_PORT`; a test picks the scenario the stub plays.
struct StubControl {
    let port: String

    init() throws {
        port = try XCTUnwrap(
            ProcessInfo.processInfo.environment["YAK_STUB_PORT"],
            "YAK_STUB_PORT is not set. Run the tests with `bundle exec fastlane ui_smoke`.")
    }

    /// The address the app is told to use, as typed and as a URL.
    var address: String { "127.0.0.1:\(port)" }
    var url: String { "http://\(address)" }

    /// A pairing link for the stub, with the code `test-code`.
    var pairingLink: URL {
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "pair"
        components.queryItems = [URLQueryItem(name: "url", value: url), URLQueryItem(name: "code", value: "test-code")]
        return components.url!
    }

    func play(_ scenario: String) throws { try post("scenario", scenario) }

    /// Takes the app offline or back online; the app in test mode reads this as its network state.
    func setOnline(_ isOnline: Bool) throws { try post("online", isOnline ? "yes" : "no") }

    /// Sends `body` to `/__stub/<endpoint>` and fails the test unless the stub answers 200. A feature's control
    /// route goes here as `<feature>/<route>`. Waits at most 30 seconds.
    func post(_ endpoint: String, _ body: String) throws {
        _ = try send("POST", endpoint, body: body)
    }

    /// The text `/__stub/<endpoint>` answers with 200. Waits at most 30 seconds.
    func get(_ endpoint: String) throws -> String {
        try send("GET", endpoint, body: nil)
    }

    /// The last Overview action the stub received, as `<method> <path>` then the body, or empty.
    func lastOverviewRequest() throws -> String { try get("overview/last-request") }

    private func send(_ method: String, _ endpoint: String, body: String?) throws -> String {
        var request = URLRequest(url: URL(string: "\(url)/__stub/\(endpoint)")!)
        request.httpMethod = method
        request.httpBody = body.map { Data($0.utf8) }
        request.timeoutInterval = 30
        let done = XCTestExpectation(description: "stub \(endpoint) answered")
        nonisolated(unsafe) var status = 0
        nonisolated(unsafe) var text = ""
        URLSession.shared.dataTask(with: request) { data, response, _ in
            status = (response as? HTTPURLResponse)?.statusCode ?? 0
            text = data.map { String(decoding: $0, as: UTF8.self) } ?? ""
            done.fulfill()
        }.resume()
        XCTWaiter().wait(for: [done], timeout: 30)
        XCTAssertEqual(status, 200, "the stub did not accept \(method) \(endpoint)")
        return text
    }
}
