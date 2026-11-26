import XCTest

extension StubControl {
    /// How the stub answers "Stop all" from now on: `ok`, `fail` (500), `slow` (held until `releaseAgents()`), `zero`
    /// (nothing ran), `limited` (429), `notfound` (404) or `unauthorized` (401).
    func setAgentsMode(_ mode: String) throws { try post("agents/mode", mode) }

    /// Lets the answers the stub holds in `slow` mode go.
    func releaseAgents() throws { try post("agents/release", "go") }

    /// How many agents run now; the page reports the change to the app.
    func setRunningAgents(_ count: Int) throws { try post("agents/running", String(count)) }

    /// The card fragment the page names as the newest running agent's.
    func setNewestAgentCard(_ fragment: String) throws { try post("agents/newest", fragment) }

    /// One line per "Stop all" request the stub took.
    func agentStopRequests() throws -> [String] {
        try get("agents/log").split(separator: "\n").map(String.init).filter { $0.hasPrefix("POST") }
    }

    /// How many held answers the stub has sent.
    func agentAnswersSent() -> Int {
        (getIfPresent("agents/log") ?? "").split(separator: "\n").filter { $0 == "answered" }.count
    }
}
