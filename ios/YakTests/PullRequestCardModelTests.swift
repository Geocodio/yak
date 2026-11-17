import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor private func card(_ fixture: TasksFixture) -> PullRequestCardModel {
    PullRequestCardModel(threadID: "d0e515ef", session: fixture.session)
}

private func routes(fix: @escaping @Sendable (String) -> (status: Int, body: Data)?) -> ScriptedTransport.Answer {
    { route, _, _ in
        if route == "GET threads/d0e515ef/pull-requests" { return (200, ScenarioFile.data("scenario-pull-requests")) }
        if route.hasPrefix("POST threads/d0e515ef/pull-requests/") { return fix(route) }
        return nil
    }
}

@MainActor @Test func rowsAndBannerComeFromTheFeed() async throws {
    let fixture = try await TasksFixture(answer: routes(fix: { _ in nil }))
    let model = card(fixture)
    await model.start()
    #expect(await fixture.eventually { model.rows.count == 2 })
    #expect(model.rows.first?.title == "#41 example/api")
    #expect(model.banner?.title == "Blocked: CI is failing in example/web")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func askToFixSendsOnceAndWaits() async throws {
    let fixture = try await TasksFixture(
        answer: routes(fix: { _ in (200, Data(#"{"status":"sent"}"#.utf8)) }))
    let model = card(fixture)
    await model.start()
    #expect(await fixture.eventually { !model.rows.isEmpty })
    await model.askToFix(78)
    await model.askToFix(78)
    #expect(model.fixing[78] == .sent)
    #expect(fixture.transport.count { $0 == "POST threads/d0e515ef/pull-requests/78/fix" } == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusalStaysUnderItsRow() async throws {
    let refusal = errorBody("ci_not_failing", "CI is not failing on this pull request.")
    let fixture = try await TasksFixture(answer: routes(fix: { _ in (409, refusal) }))
    let model = card(fixture)
    await model.start()
    #expect(await fixture.eventually { !model.rows.isEmpty })
    await model.askToFix(78)
    #expect(model.fixing[78] == .failed("CI is not failing on this pull request."))
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aThreadEventReloads() async throws {
    let fixture = try await TasksFixture(answer: routes(fix: { _ in nil }))
    let model = card(fixture)
    await model.start()
    #expect(await fixture.eventually { !model.rows.isEmpty })
    var first = ConversationSnapshot()
    await model.threadChanged(first)
    let reads = fixture.transport.count { $0 == "GET threads/d0e515ef/pull-requests" }
    first.show = try JSONDecoder().decode(ThreadShow.self, from: ScenarioFile.data("scenario-thread-with-pull-request"))
    await model.threadChanged(first)
    #expect(fixture.transport.count { $0 == "GET threads/d0e515ef/pull-requests" } > reads)
    await model.stop()
    await fixture.session.stop()
}
