import Foundation

/// A server fixture and the hand-written scenario data that stands in for it.
struct StubFixturePair: Sendable, Hashable {
    let server: String
    let scenario: String
}

struct StubContext: Sendable {
    /// The name being played, core or feature.
    let scenario: String
    let baseURL: String
    let fixtures: StubFixtures
    let threads: StubThreads
    /// What the core stub answers for this request under `paired` (for a feature scenario) or the core scenario.
    /// Runs the core route live, side effects included (a posted message is recorded). A feature that calls it
    /// must return its own reply and must not return nil, or the core route runs a second time.
    let core: @Sendable () -> StubAnswer
}

/// One feature's part of the stub: scenarios, routes, control routes and listeners of its own.
protocol StubFeature: Sendable {
    /// "push", "changes", ...; the control routes live under `/__stub/<name>`.
    var name: String { get }
    /// Scenario names this feature owns; each plays the core `paired` scenario underneath.
    var scenarios: [String] { get }
    var fixturePairs: [StubFixturePair] { get }
    /// Called for every API request in every scenario, before the core routes. `route` is the method and the path
    /// after the API prefix ("GET threads/abc"). Nil: not this feature's (the feature may still note the request).
    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply?
    /// `/__stub/<name>` and `/__stub/<name>/...` control routes.
    func control(_ request: StubRequest) -> StubReply?
    /// Extra listeners (the preview pages host, the fake terminal bridge). Bounded: throws within 5 seconds.
    func start() async throws
    /// A new scenario was played.
    func reset()
    /// Sockets close while offline.
    func setOnline(_ isOnline: Bool)
}

extension StubFeature {
    var scenarios: [String] { [] }
    var fixturePairs: [StubFixturePair] { [] }
    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? { nil }
    func control(_ request: StubRequest) -> StubReply? { nil }
    func start() async throws {}
    func reset() {}
    func setOnline(_ isOnline: Bool) {}
}

enum StubFeatures {
    /// One line per feature.
    static let all: [any StubFeature] = [StubPush()]
}

/// A feature's listener that did not start, or did not start in time.
struct StubFeatureError: Error {
    let feature: String
    let isTimeout: Bool
}
