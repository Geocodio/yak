import Foundation
import Testing
import YakKit

@testable import Yak

private func listFixture() async throws -> TasksFixture {
    try await TasksFixture { route, query, _ in
        switch route {
        case "GET tasks":
            var body = ScenarioFile.object("scenario-tasks")
            if query.contains("page=2") { body["nextPage"] = NSNull() }
            return (200, try JSONSerialization.data(withJSONObject: body))
        default: return nil
        }
    }
}

@MainActor @Test func aTabChangeReloadsPageOne() async throws {
    let fixture = try await listFixture()
    let model = TaskListModel.tasks(session: fixture.session)
    await model.start()
    #expect(await fixture.eventually { model.snapshot.rows.count == 3 })
    model.query.tab = .reviews
    #expect(await fixture.eventually { fixture.transport.all.contains("GET tasks?tab=reviews&page=1") })
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func loadMoreAtTheEnd() async throws {
    let fixture = try await listFixture()
    let model = TaskListModel.tasks(session: fixture.session)
    await model.start()
    #expect(await fixture.eventually { model.snapshot.nextPage == 2 && !model.snapshot.isShowingCachedCopy })
    await model.loadMore()
    #expect(fixture.transport.all.contains("GET tasks?tab=tasks&page=2"))
    #expect(await fixture.eventually { model.snapshot.nextPage == nil })
    await model.loadMore()
    #expect(fixture.transport.count { $0.contains("page=2") } == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func filtersBuildTheQuery() async throws {
    let fixture = try await listFixture()
    let model = TaskListModel.tasks(session: fixture.session)
    await model.start()
    #expect(await fixture.eventually { model.snapshot.rows.count == 3 })
    model.query.repo = "example/api"
    model.query.pullRequest = "open"
    #expect(
        await fixture.eventually {
            fixture.transport.all.contains {
                let decoded = $0.removingPercentEncoding ?? $0
                return decoded.contains("repo=example/api") && decoded.contains("pr=open")
            }
        })
    #expect(model.counts?.needsCoOwner == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func theTaskSearchWaitsForTheTypingToStop() async throws {
    let fixture = try await listFixture()
    let model = TaskListModel.tasks(session: fixture.session)
    await model.start()
    #expect(await fixture.eventually { model.snapshot.rows.count == 3 })
    model.search = "up"
    model.search = "upload"
    #expect(await fixture.eventually { model.query.search == "upload" })
    #expect(fixture.transport.count { $0.contains("search=up&") || $0.hasSuffix("search=up") } == 0)
    #expect(await fixture.eventually { fixture.transport.all.contains { $0.contains("search=upload") } })
    await model.stop()
    await fixture.session.stop()
}
