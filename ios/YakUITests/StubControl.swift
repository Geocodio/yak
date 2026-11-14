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

    func play(_ scenario: String) throws {
        var request = URLRequest(url: URL(string: "\(url)/__stub/scenario")!)
        request.httpMethod = "POST"
        request.httpBody = Data(scenario.utf8)
        let done = XCTestExpectation(description: "scenario set")
        nonisolated(unsafe) var status = 0
        URLSession.shared.dataTask(with: request) { _, response, _ in
            status = (response as? HTTPURLResponse)?.statusCode ?? 0
            done.fulfill()
        }.resume()
        XCTWaiter().wait(for: [done], timeout: 5)
        XCTAssertEqual(status, 200, "the stub did not accept the scenario \(scenario)")
    }
}
