import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private let api = "/api/app/v1/"

private func started() async throws -> UInt16 {
    let fixtures = try StubFixtures(
        directory: iosDirectory.appendingPathComponent("fixtures"),
        scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
    let server = try StubServer(port: 0, fixtures: fixtures, scenario: .paired)
    let port = try await server.start()
    try await server.startFeatures()
    return port
}

private func send(
    _ method: String, _ port: UInt16, _ path: String, _ body: String? = nil, origin: String? = nil
) async throws -> (Int, String) {
    var request = URLRequest(url: URL(string: "http://127.0.0.1:\(port)\(path)")!)
    request.httpMethod = method
    request.httpBody = body.map { Data($0.utf8) }
    request.timeoutInterval = 5
    if let origin { request.setValue(origin, forHTTPHeaderField: "Origin") }
    let (data, response) = try await URLSession.shared.data(for: request)
    return ((response as! HTTPURLResponse).statusCode, String(decoding: data, as: UTF8.self))
}

@Suite(.serialized) struct WorkspaceScenarioTests {
    @Test func everyVariantIsAScenarioOfTheFeature() {
        let owned = Set(StubTerminalFeature.shared.scenarios)
        for variant in WorkspaceVariant.allCases { #expect(owned.contains(variant.rawValue)) }
    }

    @Test func workspaceRoutesAnswerThroughTheServer() async throws {
        let port = try await started()
        _ = try await send("POST", port, "/__stub/scenario", "workspace")
        let (status, body) = try await send("GET", port, "\(api)threads/d0e515ef/files?scope=repo")
        #expect(status == 200)
        #expect(body.contains("README.md"))
    }

    @Test func everyStateRowIsAThread() async throws {
        let port = try await started()
        for (scenario, expected) in [
            ("terminal-states", "A thread that refuses the app"), ("files-states", "Files that fail"),
        ] {
            _ = try await send("POST", port, "/__stub/scenario", scenario)
            let list = try await send("GET", port, "\(api)threads?filter=everyone")
            #expect(list.1.contains(expected))
        }
        _ = try await send("POST", port, "/__stub/scenario", "terminal-states")
        let show = try await send("GET", port, "\(api)threads/termg0ne")
        #expect(show.0 == 200)
        #expect(show.1.contains("destroyed"))
    }

    @Test func theRestoreRowReachesTheWorkspace() async throws {
        let port = try await started()
        _ = try await send("POST", port, "/__stub/scenario", "terminal-states")
        let origin = "http://127.0.0.1:\(port)"
        let first = try await send("POST", port, "\(api)threads/termg0ne/terminal-sessions", "{}", origin: origin)
        #expect(first.0 == 409)
        let restore = try await send("POST", port, "\(api)threads/termg0ne/restore/retry")
        #expect(restore.1.contains("restoring"))
        let next = try await send("POST", port, "\(api)threads/termg0ne/terminal-sessions", "{}", origin: origin)
        #expect(next.0 == 202)
    }

    @Test func aDroppedSaveClosesWithoutAnAnswer() async throws {
        let port = try await started()
        _ = try await send("POST", port, "/__stub/scenario", "files-lost-save")
        let body = "{\"path\":\"src/Importer.php\",\"content\":\"x\",\"sha256\":\"a\"}"
        await #expect(throws: (any Error).self) {
            _ = try await send("PUT", port, "\(api)threads/d0e515ef/files/content", body)
        }
        #expect(try await send("GET", port, "/__stub/files/puts").1 == "1")
    }

    @Test func aTokenFromTheRouteOpensTheBridge() async throws {
        let port = try await started()
        _ = try await send("POST", port, "/__stub/scenario", "workspace")
        let origin = "http://127.0.0.1:\(port)"
        let answer = try await send("POST", port, "\(api)threads/d0e515ef/terminal-sessions", "{}", origin: origin)
        #expect(answer.0 == 200)
        let object = try #require(JSONSerialization.jsonObject(with: Data(answer.1.utf8)) as? [String: Any])
        let url = try #require(object["url"] as? String)
        var request = URLRequest(url: try #require(URL(string: url)))
        request.setValue(origin, forHTTPHeaderField: "Origin")
        let task = URLSession.shared.webSocketTask(with: request)
        task.resume()
        let message = try await task.receive()
        if case .data(let data) = message { #expect(String(decoding: data, as: UTF8.self).contains("Example")) }
        task.cancel(with: .goingAway, reason: nil)
        #expect(try await send("GET", port, "/__stub/terminal/opened").1 == "1")
    }
}
