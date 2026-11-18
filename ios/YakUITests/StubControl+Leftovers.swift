import XCTest

extension StubControl {
    /// The link that opens the task k3m9p2qa as a task link does.
    var taskLink: URL {
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "task"
        components.queryItems = [
            URLQueryItem(name: "instance", value: url), URLQueryItem(name: "id", value: "k3m9p2qa"),
        ]
        return components.url!
    }

    /// One request the stub's walkthrough video route saw. It carries no header value, query or body.
    struct WalkthroughVideoRequest: Decodable {
        let method: String
        let path: String
        let hasRange: Bool
        let hasAuthorization: Bool
    }

    /// The video requests of the played scenario, oldest first.
    func walkthroughVideoLog() throws -> [WalkthroughVideoRequest] {
        try JSONDecoder().decode(
            [WalkthroughVideoRequest].self, from: Data(try get("walkthrough-video/log").utf8))
    }
}
