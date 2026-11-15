import Foundation
import Testing

@testable import YakKit

private func makeCache(_ clock: ManualClock = ManualClock()) -> Cache {
    Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
}

@Suite struct TaskCacheTests {
    @Test func taskListsLiveApartFromThreadLists() async throws {
        let cache = makeCache()
        let page = try decoded(TaskListPage.self, "scenario-tasks")
        await cache.saveFirstPage(FirstPage(rows: page.tasks, nextPage: 2), key: .tasks)
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        let saved: FirstPage<TaskRow>? = await cache.firstPage(.tasks)
        #expect(saved?.rows.map(\.id) == ["k3m9p2qa", "m4n8r2tw", "p6s2v8xa"] && saved?.nextPage == 2)
        #expect(await cache.threadList(.mine) != nil)
        let observations: FirstPage<ObservationRow>? = await cache.firstPage(.observations)
        #expect(observations == nil)
    }

    @Test func fiftyOneSavedTasksKeepFifty() async throws {
        let clock = ManualClock()
        let cache = makeCache(clock)
        let task = try decoded(AppTask.self, "scenario-task")
        await cache.saveQuestionDraft(QuestionDraft(roundKey: "thread-round"), thread: "d0e515ef")
        await cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "r1"), id: "task0")
        for number in 0...50 {
            await cache.saveTask(task, id: "task\(number)")
            await clock.advance(by: .seconds(1))
        }
        #expect(await cache.task("task0") == nil)
        #expect(await cache.taskQuestionDraft("task0") == nil)
        for number in 1...50 { #expect(await cache.task("task\(number)") == task) }
        #expect(await cache.questionDraft(thread: "d0e515ef") != nil)
    }

    @Test func eraseAllRemovesTasks() async throws {
        let cache = makeCache()
        let task = try decoded(AppTask.self, "scenario-task")
        await cache.saveTask(task, id: "k3m9p2qa")
        await cache.saveFirstPage(FirstPage(rows: [TaskRow](), nextPage: nil), key: .tasks)
        await cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "r1"), id: "k3m9p2qa")
        await cache.eraseAll()
        #expect(await cache.task("k3m9p2qa") == nil)
        let page: FirstPage<TaskRow>? = await cache.firstPage(.tasks)
        #expect(page == nil)
        #expect(await cache.taskQuestionDraft("k3m9p2qa") == nil)
    }

    @Test func removeTaskDeletesTheCopyTheDraftAndTheIndexEntry() async throws {
        let cache = makeCache()
        await cache.saveTask(try decoded(AppTask.self, "scenario-task"), id: "k3m9p2qa")
        await cache.saveTaskQuestionDraft(QuestionDraft(roundKey: "r1"), id: "k3m9p2qa")
        await cache.removeTask("k3m9p2qa")
        #expect(await cache.task("k3m9p2qa") == nil)
        #expect(await cache.taskQuestionDraft("k3m9p2qa") == nil)
    }
}
