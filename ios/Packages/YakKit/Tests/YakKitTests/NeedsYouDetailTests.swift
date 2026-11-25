import Foundation
import Testing

@testable import YakKit

private func failedRow(detail: String?) -> String {
    let detailJSON = detail.map { "\"\($0)\"" } ?? "null"
    return """
        {"type": "thread", "id": "aaaaaaaa", "title": "Thread", "repo": "example/web", "status": "stopped",
         "needsYouReason": "blocked", "needsYouLabel": "Failed", "needsYouDetail": \(detailJSON), "isPrivate": false,
         "isShared": false, "isUnattended": false, "isStarred": false, "canDelete": true, "group": "today",
         "children": []}
        """
}

private func decode(_ needsYou: String) throws -> ThreadList {
    let json = """
        {"starred": [], "needsYou": [\(needsYou)], "threads": [], "needsYouCount": 1, "hasAnyThreads": true}
        """
    return try JSONDecoder().decode(ThreadList.self, from: Data(json.utf8))
}

private func firstRow(_ list: ThreadList) -> ThreadRow? {
    if case .thread(let row)? = list.needsYou.first { row } else { nil }
}

@Suite struct NeedsYouDetailTests {
    @Test func theErrorOfAFailedRowIsDecoded() throws {
        #expect(firstRow(try decode(failedRow(detail: "CI failed twice")))?.needsYouDetail == "CI failed twice")
    }

    @Test func anOlderServerSendsNone() throws {
        let row = failedRow(detail: nil).replacingOccurrences(of: "\"needsYouDetail\": null,", with: "")
        #expect(firstRow(try decode(row))?.needsYouDetail == nil)
    }

    @Test func aWrongTypeNeverFailsTheRow() throws {
        let row = failedRow(detail: nil).replacingOccurrences(
            of: "\"needsYouDetail\": null", with: "\"needsYouDetail\": 5")
        #expect(firstRow(try decode(row))?.needsYouDetail == nil)
    }

    @Test func theCachedListKeepsIt() throws {
        let list = try decode(failedRow(detail: "Expected 200 rows, got 0."))
        let copy = try JSONDecoder().decode(ThreadList.self, from: JSONEncoder().encode(list))
        #expect(firstRow(copy)?.needsYouDetail == "Expected 200 rows, got 0.")
    }

    @Test func theFoldKeepsItUnlessATaskAsksMore() throws {
        let task = """
            {"type": "task", "taskId": "t1", "threadId": "aaaaaaaa", "title": "Task", "needsYouReason": "co_owner",
             "needsYouLabel": "Waiting for your co-owner decision", "isPrivate": false}
            """
        let quiet = """
            {"type": "task", "taskId": "t2", "threadId": "aaaaaaaa", "title": "Task", "needsYouReason": "unseen",
             "needsYouLabel": "Finished, not seen yet", "isPrivate": false}
            """
        let kept = try decode(failedRow(detail: "Boom") + "," + quiet)
        #expect(firstRow(kept)?.needsYouDetail == "Boom")
        #expect(firstRow(kept)?.needsYouLabel == "Failed")
        let replaced = try decode(failedRow(detail: "Boom") + "," + task)
        #expect(firstRow(replaced)?.needsYouLabel == "Waiting for your co-owner decision")
        #expect(firstRow(replaced)?.needsYouDetail == nil)
    }

    @Test func theDrawerRowCarriesTheTrimmedError() throws {
        let list = try decode(failedRow(detail: "  Boom\\n"))
        guard
            case .sections(let sections) = DrawerSections.make(
                list: list, filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
        else {
            Issue.record("no sections")
            return
        }
        #expect(sections.first?.rows.first?.failureDetail == "Boom")
    }
}
