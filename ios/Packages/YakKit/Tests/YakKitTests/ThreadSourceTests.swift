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

    @Test func theDrawerRowCarriesTheSourceOfARootOnly() {
        let child = ThreadRow(
            id: "c1", title: "C", repo: "example/api", status: .stopped, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: false, group: .today, children: [],
            source: ThreadSource(key: .slack, label: "Slack"))
        let root = ThreadRow(
            id: "r1", title: "R", repo: "example/api", status: .stopped, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: true, group: .today, children: [child],
            source: ThreadSource(key: .slack, label: "Slack"))
        let bare = ThreadRow(
            id: "b1", title: "B", repo: "example/web", status: .stopped, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: true, group: .today, children: [])
        let list = ThreadList(starred: [], needsYou: [], threads: [root, bare], needsYouCount: 0, hasAnyThreads: true)
        guard
            case .sections(let sections) = DrawerSections.make(
                list: list, filter: .mine, search: nil, expanded: ["thread:r1": true], unsentNewThreads: [])
        else {
            Issue.record("no sections")
            return
        }
        let rows = sections.flatMap(\.rows)
        #expect(rows.map(\.source) == ["Slack", nil, nil])
        #expect(rows.map(\.repositoryDetail) == [nil, nil, "example/web"])
    }
}
