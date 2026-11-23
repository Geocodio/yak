import Foundation
import Testing

@testable import YakKit

private func threadItem(
    _ id: String, reason: String?, label: String? = nil, children: [String] = []
) -> String {
    let reasonJSON = reason.map { "\"\($0)\"" } ?? "null"
    let labelJSON = label.map { "\"\($0)\"" } ?? "null"
    return """
        {"type": "thread", "id": "\(id)", "title": "Thread \(id)", "repo": "example/web", "status": "stopped",
         "needsYouReason": \(reasonJSON), "needsYouLabel": \(labelJSON), "isPrivate": false, "isShared": false,
         "isUnattended": false, "isStarred": false, "canDelete": true, "group": "today", "children": [\(children.joined(separator: ","))]}
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

private func list(needsYou: [String], starred: [String] = [], threads: [String] = []) throws -> ThreadList {
    let json = """
        {"starred": [\(starred.joined(separator: ","))], "needsYou": [\(needsYou.joined(separator: ","))], "threads": [\(threads.joined(separator: ","))],
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

    @Test func aTaskOfAThreadListedElsewhereMovesItsRowIntoNeedsYou() throws {
        let folded = try list(
            needsYou: [
                taskItem("t1", thread: "cccccccc", reason: "co_owner", label: "Waiting for your co-owner decision")
            ],
            threads: [threadItem("cccccccc", reason: nil)])
        #expect(summary(folded) == ["thread:cccccccc:co_owner"])
        #expect(folded.threads.isEmpty)
    }

    @Test func aStarredThreadWithAPendingDecisionIsListedInNeedsYou() throws {
        let folded = try list(
            needsYou: [taskItem("t1", thread: "cccccccc", reason: "co_owner", label: "Waiting")],
            starred: [threadItem("cccccccc", reason: nil)])
        #expect(summary(folded) == ["thread:cccccccc:co_owner"])
        #expect(folded.starred.isEmpty)
    }

    @Test func aTaskOfASubThreadPutsItsRootInNeedsYouWithTheReasonOnBoth() throws {
        let child = threadItem("dddddddd", reason: nil)
        let root = threadItem("rrrrrrrr", reason: nil, children: [child])
        let folded = try list(
            needsYou: [taskItem("t1", thread: "dddddddd", reason: "question", label: "Asked you a question")],
            threads: [root])
        #expect(summary(folded) == ["thread:rrrrrrrr:question"])
        guard case .thread(let row) = folded.needsYou[0] else { return }
        #expect(row.children[0].needsYouReason == .question && folded.threads.isEmpty)
    }

    @Test func anUnknownReasonStaysVisibleAsANeedsYouRow() throws {
        let folded = try list(
            needsYou: [taskItem("t1", thread: "cccccccc", reason: "something_new", label: "Needs a look")],
            threads: [threadItem("cccccccc", reason: nil)])
        #expect(summary(folded) == ["thread:cccccccc:something_new"])
        let content = DrawerSections.make(
            list: folded, filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
        guard case .sections(let sections) = content else { return }
        #expect(sections[0].kind == .needsYou && sections[0].rows[0].subtitle == .serverLabel("Needs a look"))
    }

    @Test func aSynthesizedRowIsClosedNotStopped() throws {
        let folded = try list(needsYou: [taskItem("t1", thread: "bbbbbbbb", reason: "question", label: "Asked you")])
        guard case .thread(let row) = folded.needsYou[0] else { return }
        #expect(row.status == .closed)
    }

    @Test func theMemberwiseInitFoldsToo() throws {
        let row = try JSONDecoder().decode(ThreadRow.self, from: Data(threadItem("aaaaaaaa", reason: nil).utf8))
        let task = TaskNeedsYou(
            taskId: LenientID("t1"), threadId: "aaaaaaaa", title: "T", needsYouReason: .question,
            needsYouLabel: "Asked you")
        let made = ThreadList(
            starred: [], needsYou: [.task(task)], threads: [row], needsYouCount: 1, hasAnyThreads: true)
        #expect(summary(made) == ["thread:aaaaaaaa:question"])
    }

    @Test func everyFoldedItemReachesTheWidgetSnapshot() throws {
        let folded = try list(
            needsYou: [
                taskItem("t1", thread: "cccccccc", reason: "co_owner", label: "Waiting"),
                taskItem("t2", thread: "ssssssss", reason: "question", label: "Asked"),
                taskItem("t3", thread: "bbbbbbbb", reason: "question", label: "Asked"),
            ],
            starred: [threadItem("ssssssss", reason: nil)],
            threads: [threadItem("cccccccc", reason: nil)])
        #expect(
            Set(summary(folded)) == [
                "thread:cccccccc:co_owner", "thread:ssssssss:question", "thread:bbbbbbbb:question",
            ])
        let snapshot = WidgetSnapshot.make(
            from: folded, instanceName: "Example Yak", now: Date(timeIntervalSince1970: 0))
        #expect(Set(snapshot.needsYou.map(\.id)) == ["cccccccc", "ssssssss", "bbbbbbbb"])
        let entries = SpotlightIndexPlan.entries(
            lists: [folded], instance: SpotlightInstance(id: UUID(), name: "Example Yak"), includePrivate: true,
            now: Date(timeIntervalSince1970: 0))
        #expect(
            Set(entries.map(\.identifier.description).map { String($0.split(separator: "/").last ?? "") }) == [
                "cccccccc", "ssssssss", "bbbbbbbb",
            ])
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

    @Test(arguments: [403, 404])
    func aTaskThePersonCannotSeeIsNotFound(status: Int) async throws {
        let body = FakeTransport.Result.json(status, #"{"error":"not_found","message":"No."}"#)
        #expect(try await resolve("", body) == .notFound)
    }

    @Test func aRevokedDeviceGoesToThePairingPath() async throws {
        let body = FakeTransport.Result.json(401, #"{"error":"device_revoked","message":"Revoked."}"#)
        #expect(try await resolve("", body) == .needsPairing)
    }

    @Test(arguments: [500, 502, 503])
    func aServerErrorIsAFailure(status: Int) async throws {
        let body = FakeTransport.Result.json(status, #"{"error":"server_error","message":"Broke."}"#)
        #expect(try await resolve("", body) == .failed)
    }

    @Test func aBodyThatIsNotATaskIsAFailure() async throws {
        #expect(try await resolve("", .json(200, "<html>nope</html>")) == .failed)
    }

    @Test func noConnectionIsOffline() async throws {
        #expect(try await resolve("", .failure(.notConnectedToInternet)) == .offline)
    }

    @Test func aTimeoutIsOffline() async throws {
        #expect(try await resolve("", .failure(.timedOut)) == .offline)
    }
}
