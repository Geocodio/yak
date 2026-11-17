import Foundation
import Testing

@testable import YakStub

@Suite struct StubServerTests {
    private func send(
        _ method: String, _ port: UInt16, _ path: String, _ body: String? = nil
    ) async throws -> (Int, String) {
        var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
        request.httpMethod = method
        request.httpBody = body.map { Data($0.utf8) }
        let (data, response) = try await URLSession.shared.data(for: request)
        return ((response as! HTTPURLResponse).statusCode, String(decoding: data, as: UTF8.self))
    }

    @Test func onlineStateFollowsThePostAndResetsOnANewScenario() async throws {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(directory: directory.appendingPathComponent("fixtures"))
        let server = try StubServer(port: 0, fixtures: fixtures, scenario: .paired)
        let port = try await server.start()

        #expect(try await send("GET", port, "/__stub/online") == (200, "yes"))
        #expect(try await send("POST", port, "/__stub/online", "no") == (200, "no"))
        #expect(try await send("GET", port, "/__stub/online") == (200, "no"))
        #expect(try await send("POST", port, "/__stub/online", "off").0 == 400)
        #expect(try await send("GET", port, "/__stub/online") == (200, "no"))
        #expect(try await send("POST", port, "/__stub/scenario", "paired").0 == 200)
        #expect(try await send("GET", port, "/__stub/online") == (200, "yes"))
    }

    @Test func holdAfterTakesASequenceOrNone() async throws {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(directory: directory.appendingPathComponent("fixtures"))
        let server = try StubServer(port: 0, fixtures: fixtures, scenario: .oneThreadWorking)
        let port = try await server.start()
        #expect(try await send("POST", port, "/__stub/hold-after", "7") == (200, "7"))
        #expect(try await send("POST", port, "/__stub/hold-after", "none") == (200, "none"))
    }
}
