import Foundation
import Testing

@testable import YakKit

private func decodedRow(source: String?) throws -> ThreadRow {
    let sourceJSON = source.map { ", \"source\": \($0)" } ?? ""
    let json = """
        {"id": "a1", "title": "T", "repo": "example/api", "status": "stopped", "isPrivate": false, "isShared": false,
         "isUnattended": false, "isStarred": false, "canDelete": true, "group": "today", "children": []\(sourceJSON)}
        """
    return try JSONDecoder().decode(ThreadRow.self, from: Data(json.utf8))
}

@Suite struct ThreadSourceTests {
    @Test func theServerFixtureRowCarriesItsSource() throws {
        let list = try JSONDecoder().decode(ThreadList.self, from: Fixtures.data("threads"))
        let rows = list.needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        let source = try #require(rows.first?.source)
        #expect(source == ThreadSource(key: .dashboard, label: "Dashboard"))
        #expect(source.displayName == "Dashboard")
    }

    @Test func aRowWithoutSourceDecodes() throws {
        #expect(try decodedRow(source: nil).source == nil)
        #expect(try decodedRow(source: "null").source == nil)
    }

    @Test func anUnknownKeyShowsItsLabelAndIgnoresUnknownFields() throws {
        let row = try decodedRow(
            source: #"{"key": "pagerduty", "label": "PagerDuty", "reference": "P1", "url": null, "extra": 1}"#)
        #expect(row.source?.key.rawValue == "pagerduty")
        #expect(row.source?.displayName == "PagerDuty")
        #expect(row.source?.url == nil)
    }

    @Test func aSourceWithMissingFieldsStillDecodes() throws {
        #expect(try decodedRow(source: #"{"key": "slack"}"#).source?.displayName == "slack")
        #expect(try decodedRow(source: "{}").source?.displayName == nil)
        #expect(try decodedRow(source: #"{"key": 5, "label": "Slack"}"#).source?.displayName == "Slack")
    }

    @Test func theSourceSurvivesTheSavedCopyAndTheNeedsYouFold() throws {
        let source = ThreadSource(key: .slack, label: "Slack", reference: "#ops", url: "https://example.com/p/1")
        let row = ThreadRow(
            id: "t1", title: "T", repo: "example/api", status: .stopped, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: true, group: .today, children: [], source: source)
        let task = TaskNeedsYou(
            taskId: LenientID("k1"), threadId: "t1", title: "T", needsYouReason: .question,
            needsYouLabel: "Asked you a question")
        let list = ThreadList(
            starred: [], needsYou: [.task(task)], threads: [row], needsYouCount: 1, hasAnyThreads: true)
        guard case .thread(let folded) = try #require(list.needsYou.first) else {
            Issue.record("no row")
            return
        }
        #expect(folded.source == source)
        let copy = try JSONDecoder().decode(ThreadList.self, from: JSONEncoder().encode(list))
        #expect(copy == list)
    }

    @Test func aSourceOfTheWrongTypeNeverFailsTheRow() throws {
        #expect(try decodedRow(source: #""slack""#).source == nil)
        #expect(try decodedRow(source: #"["slack"]"#).source == nil)
        #expect(try decodedRow(source: "5").source == nil)
    }

    @Test func theSummaryHidesWhatIsMissing() {
        #expect(ThreadSource(key: .slack, label: "Slack", reference: "#ops").summary == "Slack #ops")
        #expect(ThreadSource(key: .slack, label: "", reference: nil).summary == "slack")
        #expect(ThreadSource(key: SourceKey(rawValue: ""), label: "", reference: "ENG-1").summary == "ENG-1")
        #expect(ThreadSource(key: SourceKey(rawValue: ""), label: " ", reference: " ").summary == nil)
    }

    @Test func theDrawerRowCarriesTheSourceOfARootOnly() {
        let child = threadRow(
            "c1", status: .stopped, canDelete: false, source: ThreadSource(key: .slack, label: "Slack"))
        let root = threadRow(
            "r1", status: .stopped, children: [child], source: ThreadSource(key: .slack, label: "Slack"))
        let bare = threadRow("b1", repo: "example/web", status: .stopped)
        let rows = drawerRows([root, bare], expanded: ["thread:r1": true])
        #expect(rows.map(\.source) == ["Slack", nil, nil])
        #expect(rows.map(\.repository) == ["example/api", nil, "example/web"])
    }

    @Test(arguments: [
        ("stopped", true), ("paused", true), ("waiting_for_repository", true), ("closed", false),
        ("running", false), ("blocked", false), ("something_new", false),
    ])
    func theRepositoryReplacesOnlyAStatusWhoseMarkDiffersWithoutColor(status: String, replaces: Bool) {
        let source = ThreadSource(key: .slack, label: "Slack")
        for badge in [source, nil] {
            let row = drawerRows([
                threadRow("a1", repo: "example/web", status: ThreadRowStatus(rawValue: status), source: badge)
            ])[0]
            #expect(row.repositoryDetail == (replaces ? "example/web" : nil))
            #expect(row.source == badge?.label)
        }
    }

    @Test func aReasonOrAnEmptyRepositoryKeepsTheWords() {
        let unseen = drawerRows([threadRow("a1", status: .stopped, reason: .unseen)])[0]
        #expect(unseen.repositoryDetail == nil)
        let noRepository = drawerRows([threadRow("a2", repo: "", status: .stopped)])[0]
        #expect(noRepository.repositoryDetail == nil)
    }
}

private func threadRow(
    _ id: String, repo: String = "example/api", status: ThreadRowStatus, reason: NeedsYouReason? = nil,
    canDelete: Bool = true, children: [ThreadRow] = [], source: ThreadSource? = nil
) -> ThreadRow {
    ThreadRow(
        id: id, title: id, repo: repo, status: status, needsYouReason: reason, isPrivate: false, isShared: false,
        isUnattended: false, isStarred: false, canDelete: canDelete, group: .today, children: children, source: source)
}

private func drawerRows(_ threads: [ThreadRow], expanded: [String: Bool] = [:]) -> [DrawerRow] {
    let list = ThreadList(starred: [], needsYou: [], threads: threads, needsYouCount: 0, hasAnyThreads: true)
    guard
        case .sections(let sections) = DrawerSections.make(
            list: list, filter: .mine, search: nil, expanded: expanded, unsentNewThreads: [])
    else { return [] }
    return sections.flatMap(\.rows)
}
