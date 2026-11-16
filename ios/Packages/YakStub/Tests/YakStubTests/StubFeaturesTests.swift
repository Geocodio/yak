import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private func fixtures() throws -> StubFixtures {
    try StubFixtures(
        directory: iosDirectory.appendingPathComponent("fixtures"),
        scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
}

/// A feature that owns `sample-one`, answers one thread route, notes message posts without answering them and
/// counts what the server tells it.
private final class SampleFeature: StubFeature, @unchecked Sendable {
    let name = "sample"
    let scenarios = ["sample-one"]
    let fixturePairs = [StubFixturePair(server: "sample", scenario: "scenario-sample")]
    let noted = Locked<[String]>([])
    let resets = Locked(0)
    let onlineStates = Locked<[Bool]>([])
    let startBehavior: Locked<Behavior> = Locked(.succeed)

    enum Behavior: Sendable { case succeed, fail, hang }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        if route == "GET threads/d0e515ef/sample" {
            return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(context.scenario.utf8)))
        }
        if route.hasPrefix("POST threads/"), route.hasSuffix("/messages") { noted.mutate { $0.append(route) } }
        return nil
    }

    func control(_ request: StubRequest) -> StubReply? {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data("sample control".utf8)))
    }

    func start() async throws {
        switch startBehavior.get() {
        case .succeed: return
        case .fail: throw URLError(.cannotConnectToHost)
        case .hang: try await Task.sleep(for: .seconds(60))
        }
    }

    func reset() { resets.mutate { $0 += 1 } }
    func setOnline(_ isOnline: Bool) { onlineStates.mutate { $0.append(isOnline) } }
}

private func send(
    _ method: String, _ port: UInt16, _ path: String, _ body: String? = nil
) async throws -> (Int, String) {
    var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
    request.httpMethod = method
    request.httpBody = body.map { Data($0.utf8) }
    request.timeoutInterval = 5
    let (data, response) = try await URLSession.shared.data(for: request)
    return ((response as! HTTPURLResponse).statusCode, String(decoding: data, as: UTF8.self))
}

private let api = "/api/app/v1/"

@Suite struct StubFeaturesTests {
    @Test func scenarioNamesAreUnique() {
        var owners: [String: String] = [:]
        func claim(_ name: String, by owner: String) {
            #expect(owners[name] == nil, "\(name) is owned by \(owners[name] ?? "") and \(owner)")
            owners[name] = owner
        }
        for scenario in Scenario.allCases { claim(scenario.rawValue, by: "core") }
        for name in StubAttachments.scenarioNames { claim(name, by: "attachments") }
        for feature in StubFeatures.all { for name in feature.scenarios { claim(name, by: feature.name) } }
    }

    @Test func featureNamesAreUniqueAndDoNotShadowTheStubRoutes() {
        let names = StubFeatures.all.map(\.name)
        #expect(Set(names).count == names.count)
        #expect(Set(names).isDisjoint(with: ["scenario", "online", "last-message"]))
    }

    @Test func fixturePairsAreUnique() {
        let pairs = StubFixtures.names
        #expect(Set(pairs.map(\.server)).count == pairs.count)
        #expect(Set(pairs.map(\.scenario)).count == pairs.count)
    }

    @Test func everyFeaturePairHasScenarioData() throws {
        let loaded = try fixtures()
        for pair in StubFixtures.names {
            #expect(!loaded.scenario(pair.scenario).isEmpty, "\(pair.scenario) has no data")
        }
    }

    @Test func aFeatureScenarioPlaysPairedUnderneath() async throws {
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [SampleFeature()])
        let port = try await server.start()
        #expect(try await send("POST", port, "/__stub/scenario", "sample-one") == (200, "sample-one"))
        #expect(try await send("GET", port, "\(api)threads").0 == 200)
        #expect(try await send("GET", port, "\(api)threads/d0e515ef/sample") == (200, "sample-one"))
        #expect(try await send("POST", port, "/__stub/scenario", "nobody-owns-this").0 == 400)
        #expect(try await send("POST", port, "/__stub/scenario", "paired") == (200, "paired"))
    }

    @Test func aFeatureMayNoteAndPass() async throws {
        let sample = SampleFeature()
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [sample])
        let port = try await server.start()
        let (status, _) = try await send("POST", port, "\(api)threads/d0e515ef/messages", "{\"body\":\"hi\"}")
        #expect(status == 200 || status == 202)
        #expect(sample.noted.get() == ["POST threads/d0e515ef/messages"])
    }

    @Test func controlRoutesReachTheirFeature() async throws {
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [SampleFeature()])
        let port = try await server.start()
        #expect(try await send("GET", port, "/__stub/sample") == (200, "sample control"))
        #expect(try await send("GET", port, "/__stub/sample/deeper") == (200, "sample control"))
        #expect(try await send("GET", port, "/__stub/other").0 == 404)
        #expect(try await send("GET", port, "/__stub/samplex").0 == 404)
    }

    @Test func resetAndOfflineFanOut() async throws {
        let sample = SampleFeature()
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [sample])
        let port = try await server.start()
        _ = try await send("POST", port, "/__stub/scenario", "sample-one")
        #expect(sample.resets.get() == 1)
        _ = try await send("POST", port, "/__stub/online", "no")
        #expect(sample.onlineStates.get().last == false)
        // Offline drops the request before any feature sees it.
        await #expect(throws: (any Error).self) { try await send("GET", port, "\(api)threads/d0e515ef/sample") }
        _ = try await send("POST", port, "/__stub/online", "yes")
        #expect(sample.onlineStates.get().last == true)
    }

    @Test func aListenerThatFailsOrHangsIsNamed() async throws {
        let sample = SampleFeature()
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [sample])
        _ = try await server.start()
        try await server.startFeatures()
        sample.startBehavior.set(.fail)
        let failure = await #expect(throws: StubFeatureError.self) { try await server.startFeatures() }
        #expect(failure?.feature == "sample" && failure?.isTimeout == false)
    }

    @Test func aListenerThatNeverStartsTimesOut() async throws {
        let sample = SampleFeature()
        sample.startBehavior.set(.hang)
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [sample])
        _ = try await server.start()
        let started = ContinuousClock.now
        let failure = await #expect(throws: StubFeatureError.self) { try await server.startFeatures() }
        #expect(failure?.feature == "sample" && failure?.isTimeout == true)
        #expect(ContinuousClock.now - started < .seconds(8))
    }
}
