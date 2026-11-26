import Foundation
import Testing

@testable import YakKit

private func row(_ id: String, _ title: String, repo: String = "example/web", children: [ThreadRow] = []) -> ThreadRow {
    ThreadRow(
        id: id, title: title, repo: repo, status: ThreadRowStatus(rawValue: "stopped"), needsYouReason: nil,
        isPrivate: false, isShared: false, isUnattended: false, isStarred: false, canDelete: true,
        group: ThreadRowGroup(rawValue: "today"), children: children, step: nil, stackState: nil)
}

@Suite struct ThreadListNarrowingTests {
    private let list = ThreadList(
        starred: [row("s1", "Starred geocoder work")],
        needsYou: [.thread(row("n1", "Batch geocode retries")), .thread(row("n2", "Unrelated"))],
        threads: [
            row("t1", "Move search", repo: "example/geocoder"),
            row("t2", "Dark mode", children: [row("c1", "Fix the Geocoder cache")]),
            row("t3", "Dark docs"),
        ], needsYouCount: 2, hasAnyThreads: true,
        reviewGroups: [
            ReviewGroup(key: "waiting", label: "Waiting", rows: [row("r1", "Review geocode"), row("r2", "No")])
        ])

    @Test func keepsRowsByTitleRepositoryOrSubThread() {
        let narrowed = list.narrowed(to: "geocod")
        #expect(narrowed.starred.map(\.id) == ["s1"])
        #expect(narrowed.needsYou.count == 1)
        #expect(narrowed.threads.map(\.id) == ["t1", "t2"])
        #expect(narrowed.reviewGroups.first?.rows.map(\.id) == ["r1"])
    }

    @Test func matchingIgnoresCaseAndAccentsAndTrimsTheText() {
        #expect(list.narrowed(to: "  DARK ").threads.map(\.id) == ["t2", "t3"])
    }

    @Test func aMatchingRowKeepsItsSubThreads() {
        #expect(list.narrowed(to: "dark mode").threads.first?.children.map(\.id) == ["c1"])
    }

    @Test func emptyTextChangesNothing() {
        #expect(list.narrowed(to: "  ") == list)
    }

    @Test func countsStayTheListsOwn() {
        let narrowed = list.narrowed(to: "zzz")
        #expect(narrowed.threads.isEmpty && narrowed.needsYouCount == 2 && narrowed.hasAnyThreads)
    }
}
