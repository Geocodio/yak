import Foundation
import Testing

@testable import YakStub

@Suite struct QuestionRoutesTests {
    private func send(_ feature: StubQuestions, _ scenario: String, _ body: String = "{}") -> (Int, String)? {
        let context = StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: try! fixtures(), threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: Data(#"{"status":"sent"}"#.utf8)) })
        let request = StubRequest(
            method: "POST", path: "x", body: Data(body.utf8))
        guard
            let reply = feature.answer(
                route: "POST threads/d0e515ef/clarification-answers", request: request, context: context),
            case .reply(let status, _, let data) = reply.answer
        else { return nil }
        return (status, String(decoding: data, as: UTF8.self))
    }

    private func fixtures() throws -> StubFixtures {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
    }

    @Test func aClosedRoundIsRefusedWithQuestionClosed() throws {
        let feature = StubQuestions()
        let answer = try #require(send(feature, "question-closed", #"{"answers":{}}"#))
        #expect(answer.0 == 409 && answer.1.contains("question_closed"))
    }

    @Test func theFirstSendFailsAndTheSecondSucceeds() throws {
        let feature = StubQuestions()
        #expect(try #require(send(feature, "question-retry")).0 == 500)
        #expect(try #require(send(feature, "question-retry")).0 == 200)
        feature.reset()
        #expect(try #require(send(feature, "question-retry")).0 == 500)
    }

    @Test func otherScenariosAreLeftToTheCore() {
        #expect(send(StubQuestions(), "question-open") == nil)
    }

    @Test func theLastBodyIsKept() throws {
        let feature = StubQuestions()
        _ = send(feature, "question-closed", #"{"note":"x"}"#)
        let reply = try #require(feature.control(StubRequest(method: "GET", path: "/__stub/question/last-request")))
        guard case .reply(_, _, let body) = reply.answer else {
            Issue.record("no reply")
            return
        }
        #expect(String(decoding: body, as: UTF8.self) == #"{"note":"x"}"#)
    }

    @Test func theReadOnlyScenarioTakesTheRightToDriveFromTheShow() throws {
        let context = StubContext(
            scenario: "question-readonly", baseURL: "http://127.0.0.1", fixtures: try fixtures(),
            threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: Data(#"{"canDrive":true}"#.utf8)) })
        let reply = try #require(
            StubQuestions().answer(
                route: "GET threads/d0e515ef", request: StubRequest(method: "GET", path: "x", body: Data()),
                context: context))
        guard case .reply(_, _, let body) = reply.answer else {
            Issue.record("no reply")
            return
        }
        #expect(String(decoding: body, as: UTF8.self).contains(#""canDrive":false"#))
    }

    @Test func theQuestionFeatureAnswersTheShowBeforeTheChangesFeature() throws {
        let all = StubFeatures.all.map { $0.name }
        #expect(try #require(all.firstIndex(of: "question")) < #require(all.firstIndex(of: "changes")))
    }
}
