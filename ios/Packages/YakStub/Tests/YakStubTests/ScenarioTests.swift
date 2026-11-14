import Foundation
import Testing

@testable import YakStub

private func fixtures() throws -> StubFixtures {
    // Packages/YakStub/Tests/YakStubTests -> ios/fixtures
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return try StubFixtures(directory: directory.appendingPathComponent("fixtures"))
}

private func reply(_ scenario: Scenario, _ method: String, _ path: String, signedIn: Bool = false) throws -> StubReply {
    let headers = signedIn ? ["authorization": "Bearer stub-token"] : [:]
    let request = StubRequest(method: method, path: "/api/app/v1/\(path)", headers: headers)
    return scenario.reply(to: request, baseURL: "http://127.0.0.1:1", fixtures: try fixtures())
}

private func status(_ reply: StubReply) -> Int? {
    if case .reply(let status, _, _) = reply.answer { return status }
    return nil
}

private func json(_ reply: StubReply) throws -> [String: Any] {
    guard case .reply(_, _, let body) = reply.answer else { return [:] }
    return try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
}

@Suite struct ScenarioTests {
    @Test func pairedAnswersEveryEndpointWithTheFixtureShape() throws {
        #expect(try json(reply(.paired, "GET", "instance"))["yak"] as? Bool == true)
        let me = try json(reply(.paired, "GET", "me", signedIn: true))
        #expect((me["user"] as? [String: Any])?["name"] as? String == "Test Person")
        #expect((me["instance"] as? [String: Any])?["url"] as? String == "http://127.0.0.1:1")
        #expect(try json(reply(.paired, "POST", "device/pair"))["token"] as? String == "stub-token")
        #expect(try status(reply(.paired, "GET", "me")) == 401)
    }

    @Test func noMarkerSurvivesInAnyFixture() throws {
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            let body = try fixtures().body(name, baseURL: "http://127.0.0.1:1")
            #expect(!String(decoding: body, as: UTF8.self).contains("<volatile"))
        }
    }

    @Test func revokedRefusesTheDevice() throws {
        let answer = try reply(.revoked, "GET", "me", signedIn: true)
        #expect(status(answer) == 401)
        #expect(try json(answer)["error"] as? String == "device_revoked")
    }

    @Test func serverTooNewAsksForAnUpdate() throws {
        let api = try json(reply(.serverTooNew, "GET", "instance"))["api"] as? [String: Int]
        #expect(api?["min"] == 99)
        let answer = try reply(.serverTooNew, "GET", "me", signedIn: true)
        #expect(status(answer) == 426)
        #expect(((try json(answer)["details"]) as? [String: Any])?["min_protocol"] as? Int == 99)
    }

    @Test func serverTooOldStampsProtocolZero() throws {
        let answer = try reply(.serverTooOld, "GET", "instance")
        #expect(answer.protocolHeader == 0)
    }

    @Test func notYakAnswersHTMLEverywhere() throws {
        for path in ["instance", "me", "device/pair"] {
            guard case .reply(200, let type, _) = try reply(.notYak, "GET", path).answer else {
                Issue.record("expected 200 HTML for \(path)")
                continue
            }
            #expect(type == "text/html")
        }
    }

    @Test func slowDelaysAndDownDrops() throws {
        #expect(try reply(.slow, "GET", "instance").delay == Scenario.slowDelay)
        #expect(try reply(.paired, "GET", "instance").delay == .zero)
        #expect(try reply(.down, "GET", "instance").answer == .drop)
    }

    @Test func brokenFailsWithTheErrorShape() throws {
        let answer = try reply(.broken, "GET", "me", signedIn: true)
        #expect(status(answer) == 500)
        #expect(try json(answer)["error"] as? String == "server_error")
    }

    @Test func usedAndExpiredCodesAreRefusedOnPairOnly() throws {
        #expect(try json(reply(.codeUsed, "POST", "device/pair"))["error"] as? String == "pairing_code_invalid")
        #expect(try json(reply(.codeExpired, "POST", "device/pair"))["error"] as? String == "pairing_code_expired")
        #expect(try status(reply(.codeUsed, "GET", "instance")) == 200)
    }

    @Test func approvalScenariosAnswerTheTokenPollWithTheirErrorAndEverythingElseLikePaired() throws {
        let cases: [(Scenario, String)] = [
            (.approvalPending, "authorization_pending"), (.approvalDenied, "access_denied"),
            (.approvalExpired, "expired_token"), (.approvalSlowDown, "slow_down"),
        ]
        for (scenario, code) in cases {
            let answer = try reply(scenario, "POST", "device/token")
            #expect(status(answer) == 400)
            #expect(try json(answer)["error"] as? String == code)
            #expect(try json(reply(scenario, "POST", "device/code"))["device_code"] as? String == "stub-device-code")
            #expect(try json(reply(scenario, "GET", "instance"))["yak"] as? Bool == true)
        }
        #expect(try status(reply(.paired, "POST", "device/token")) == 200)
    }

    @Test func everyScenarioNameParses() {
        for scenario in Scenario.allCases { #expect(Scenario(rawValue: scenario.rawValue) == scenario) }
    }
}
