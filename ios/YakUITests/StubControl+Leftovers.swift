import XCTest

extension StubControl {
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
