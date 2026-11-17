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

private func object(_ text: String) throws -> [String: Any] {
    try #require(try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any])
}

private let api = "/api/app/v1/threads/d0e515ef"

@Suite struct PreviewScenarioTests {
    private func play(_ name: String) async throws -> UInt16 {
        let server = try StubServer(port: 0, fixtures: fixtures(), scenario: .paired, features: [StubPreviewFeature()])
        let port = try await server.start()
        try await server.startFeatures()
        #expect(try await send("POST", port, "/__stub/scenario", name).0 == 200)
        return port
    }

    @Test func everyPreviewScenarioOpensItsThread() async throws {
        for scenario in StubPreviewPlay.allCases {
            let port = try await play(scenario.rawValue)
            let (status, body) = try await send("GET", port, api)
            #expect(status == 200, "\(scenario.rawValue)")
            let show = try object(body)
            #expect((show["panes"] as? [String: Any])?["preview"] as? Bool == true, "\(scenario.rawValue)")
            let thread = show["thread"] as? [String: Any]
            switch scenario {
            case .restoreFailed: #expect(thread?["blockedReason"] as? String == "restore_failed")
            case .readOnly: #expect(show["canDrive"] as? Bool == false)
            case .destroyed: #expect(thread?["sandboxState"] as? String == "destroyed")
            case .closed: #expect(thread?["status"] as? String == "closed")
            default: break
            }
        }
    }

    @Test func aFixMessageStartsTheFix() async throws {
        let port = try await play("preview-failing")
        let first = try await send("GET", port, "\(api)/preview/status")
        #expect(first.0 == 200 && first.1.contains("\"failing\""), "\(first)")
        _ = try await send("POST", port, "\(api)/messages", "{\"text\":\"fix\",\"intent\":\"fix_preview\"}")
        let setup = try object(try await send("GET", port, "\(api)/preview/status").1)["setup"] as? [String: Any]
        #expect(setup?["isRunning"] as? Bool == true)
        #expect(try await send("GET", port, "/__stub/last-message").1.contains("fix_preview"))
    }

    @Test func theBounceIsAnErrorPage() async throws {
        let port = try await play("paired")
        let (status, body) = try await send("GET", port, "/deployments/auth-bounce?to=x")
        #expect(status == 200)
        #expect(body.contains("The app showed the dashboard bounce."))
    }

    @Test func thePreviewLogIsServed() async throws {
        let port = try await play("preview-ready")
        _ = try await send("GET", port, "\(api)/preview/status")
        _ = try await send("POST", port, "\(api)/preview/warm")
        let log = try await send("GET", port, "/__stub/preview/log").1
        #expect(log.contains("status ready"))
        #expect(log.contains("warm"))
    }

    @Test func serverFixturesAnswerThePreviewRoutes() async throws {
        let port = try await play("server-fixtures")
        let (status, body) = try await send("GET", port, "\(api)/preview/status")
        #expect(status == 200)
        #expect(try object(body)["state"] is String)
        #expect(try await send("POST", port, "\(api)/preview/restore").0 == 202)
    }

    @Test func previewNamesHaveScenarioData() throws {
        let loaded = try fixtures()
        for pair in StubPreviewFeature().fixturePairs { #expect(!loaded.scenario(pair.scenario).isEmpty) }
    }
}
