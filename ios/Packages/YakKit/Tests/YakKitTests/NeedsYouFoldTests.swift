import Foundation
import Testing

@testable import YakKit

private func threadItem(_ id: String, reason: String?, label: String? = nil) -> String {
    let reasonJSON = reason.map { "\"\($0)\"" } ?? "null"
    let labelJSON = label.map { "\"\($0)\"" } ?? "null"
    return """
        {"type": "thread", "id": "\(id)", "title": "Thread \(id)", "repo": "example/web", "status": "stopped",
         "needsYouReason": \(reasonJSON), "needsYouLabel": \(labelJSON), "isPrivate": false, "isShared": false,
         "isUnattended": false, "isStarred": false, "canDelete": true, "group": "today", "children": []}
        """
}

private func taskItem(_ id: String, thread: String?, reason: String, label: String) -> String {
    let threadJSON = thread.map { "\"\($0)\"" } ?? "null"
    return """
        {"type": "task", "taskId": "\(id)", "threadId": \(threadJSON), "title": "Task \(id)",
         "needsYouReason": "\(reason)", "needsYouLabel": "\(label)", "isPrivate": false,
         "lastActivityAt": "2026-11-15T12:00:00+00:00"}
        """
}

private func list(needsYou: [String], threads: [String] = []) throws -> ThreadList {
    let json = """
        {"starred": [], "needsYou": [\(needsYou.joined(separator: ","))], "threads": [\(threads.joined(separator: ","))],
         "needsYouCount": \(needsYou.count), "hasAnyThreads": true}
        """
    return try JSONDecoder().decode(ThreadList.self, from: Data(json.utf8))
}

private func summary(_ list: ThreadList) -> [String] {
    list.needsYou.map { item in
        switch item {
        case .thread(let row): "thread:\(row.id):\(row.needsYouReason?.rawValue ?? "-")"
        case .task(let task): "task:\(task.taskId.value)"
        case .unknown: "unknown"
        }
    }
}

@Suite struct NeedsYouFoldTests {
    @Test func aTaskOfAnOpenThreadLendsItsMoreUrgentReasonAndLeaves() throws {
        let folded = try list(needsYou: [
            threadItem("aaaaaaaa", reason: "unseen", label: "Finished, not seen yet"),
            taskItem("t1", thread: "aaaaaaaa", reason: "co_owner", label: "Waiting for your co-owner decision"),
        ])
        #expect(summary(folded) == ["thread:aaaaaaaa:co_owner"])
        guard case .thread(let row) = folded.needsYou[0] else { return }
        #expect(row.needsYouLabel == "Waiting for your co-owner decision")
    }

    @Test func aThreadRowKeepsItsOwnReasonWhenTheTaskAsksLess() throws {
        let folded = try list(needsYou: [
            taskItem("t1", thread: "aaaaaaaa", reason: "question", label: "Asked you a question"),
            threadItem("aaaaaaaa", reason: "co_owner", label: "Waiting for your co-owner decision"),
        ])
        #expect(summary(folded) == ["thread:aaaaaaaa:co_owner"])
    }

    @Test func aTaskOfAClosedThreadBecomesAThreadRow() throws {
        let folded = try list(needsYou: [taskItem("t1", thread: "bbbbbbbb", reason: "question", label: "Asked you")])
        #expect(summary(folded) == ["thread:bbbbbbbb:question"])
    }

    @Test func twoTasksOfOneThreadMakeOneRow() throws {
        let folded = try list(needsYou: [
            taskItem("t1", thread: "bbbbbbbb", reason: "question", label: "Asked you"),
            taskItem("t2", thread: "bbbbbbbb", reason: "co_owner", label: "Waiting"),
        ])
        #expect(summary(folded) == ["thread:bbbbbbbb:co_owner"])
    }

    @Test func aTaskWhoseThreadIsListedElsewhereLeavesNoSecondRow() throws {
        let folded = try list(
            needsYou: [taskItem("t1", thread: "cccccccc", reason: "question", label: "Asked you")],
            threads: [threadItem("cccccccc", reason: nil)])
        #expect(summary(folded).isEmpty)
    }

    @Test func aTaskWithoutAThreadStaysATaskItem() throws {
        let folded = try list(needsYou: [taskItem("t1", thread: nil, reason: "question", label: "Asked you")])
        #expect(summary(folded) == ["task:t1"])
    }

    @Test func aSavedCopyFoldsToo() throws {
        let first = try list(needsYou: [
            threadItem("aaaaaaaa", reason: "question", label: "Asked you a question"),
            taskItem("t1", thread: "aaaaaaaa", reason: "question", label: "Asked you a question"),
        ])
        let saved = try JSONEncoder().encode(first)
        #expect(summary(try JSONDecoder().decode(ThreadList.self, from: saved)) == ["thread:aaaaaaaa:question"])
    }

    @Test func aBlockedThreadShowsTheBlockedDotNotTheAnswerDot() throws {
        let content = DrawerSections.make(
            list: try list(needsYou: [threadItem("aaaaaaaa", reason: "blocked", label: "Blocked: CI failed twice")]),
            filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
        guard case .sections(let sections) = content else { return }
        #expect(sections[0].rows[0].indicator == .blocked)
    }
}

@Suite struct TaskThreadResolverTests {
    private func resolve(_ route: String, _ result: FakeTransport.Result) async throws -> TaskTarget {
        let transport = ScriptedTransport()
        await transport.on("GET tasks/k3m9p2qa", result)
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        return await TaskThreadResolver.resolve(taskID: "k3m9p2qa", client: client)
    }

    @Test func aTaskOnAThreadOpensTheThread() async throws {
        #expect(try await resolve("", try .scenario(200, "scenario-task")) == .thread("d0e515ef"))
    }

    @Test func aTaskWithoutAThreadOpensItsWebPage() async throws {
        #expect(try await resolve("", try .scenario(200, "scenario-task-question")) == .webPage)
    }

    @Test func anUnknownTaskIsNotFound() async throws {
        let missing = FakeTransport.Result.json(404, #"{"error":"not_found","message":"No."}"#)
        #expect(try await resolve("", missing) == .notFound)
    }

    @Test func anUnreachableServerIsAFailureNotANotFound() async throws {
        #expect(try await resolve("", .failure(.notConnectedToInternet)) == .failed)
    }
}
