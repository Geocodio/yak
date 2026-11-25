import Foundation
import Testing

@testable import YakKit

private func failedRow(detail: String?, reason: String = "blocked", label: String = "Failed") -> String {
    let detailJSON = detail.map { "\"\($0)\"" } ?? "null"
    return """
        {"type": "thread", "id": "aaaaaaaa", "title": "Thread", "repo": "example/web", "status": "stopped",
         "needsYouReason": "\(reason)", "needsYouLabel": "\(label)", "needsYouDetail": \(detailJSON), "isPrivate": false,
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

    private func row(_ json: String) throws -> DrawerRow? {
        let list = try decode(json)
        guard
            case .sections(let sections) = DrawerSections.make(
                list: list, filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
        else { return nil }
        return sections.first?.rows.first
    }

    /// JSON escapes: `\n` and `\t` inside the Swift literal become the JSON escapes for a newline and a tab.
    @Test func everyRunOfWhitespaceIsOneSpaceInTheRowButKeptInThePreview() throws {
        let trace = "Error: boom\\n    at run (job.js:10)\\n\\tat main (job.js:3)\\r\\n\\n  done"
        let result = try #require(try row(failedRow(detail: trace)))
        #expect(result.failureDetail == "Error: boom at run (job.js:10) at main (job.js:3) done")
        #expect(result.failureFullText == "Error: boom\n    at run (job.js:10)\n\tat main (job.js:3)\r\n\n  done")
    }

    @Test func emojiAndRightToLeftTextSurvive() throws {
        let text =
            "Build failed \u{1F4A5}\\n\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{05E9}\u{05DC}\u{05D5}\u{05DD}\\n\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}"
        let result = try #require(try row(failedRow(detail: text)))
        #expect(
            result.failureDetail
                == "Build failed \u{1F4A5} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{0645}\u{0631}\u{062D}\u{0628}\u{0627}"
        )
    }

    @Test func aBlankErrorShowsNothing() throws {
        #expect(try row(failedRow(detail: " \\n\\t "))?.failureDetail == nil)
    }

    @Test func onlyTheFailureReasonWithItsLabelShowsTheText() throws {
        #expect(try row(failedRow(detail: "Boom"))?.failureDetail == "Boom")
        #expect(
            try row(failedRow(detail: "Boom", reason: "question", label: "Asked you a question"))?.failureDetail == nil)
        #expect(try row(failedRow(detail: "Boom", reason: "blocked", label: "Something else"))?.failureDetail == nil)
        #expect(try row(failedRow(detail: "Boom", reason: "unseen", label: "Failed"))?.failureDetail == nil)
    }

    @Test func aRowWithNoLabelHasNoError() throws {
        let json = """
            {"type": "thread", "id": "aaaaaaaa", "title": "Thread", "repo": "example/web", "status": "stopped",
             "needsYouReason": null, "needsYouLabel": null, "needsYouDetail": "Boom", "isPrivate": false,
             "isShared": false, "isUnattended": false, "isStarred": false, "canDelete": true, "group": "today",
             "children": []}
            """
        let list = try JSONDecoder().decode(
            ThreadList.self,
            from: Data(
                """
                {"starred": [], "needsYou": [], "threads": [\(json)], "needsYouCount": 0, "hasAnyThreads": true}
                """.utf8))
        guard
            case .sections(let sections) = DrawerSections.make(
                list: list, filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
        else {
            Issue.record("no sections")
            return
        }
        #expect(sections.first?.rows.first?.failureDetail == nil)
        #expect(sections.first?.rows.first?.failureFullText == nil)
    }
}
