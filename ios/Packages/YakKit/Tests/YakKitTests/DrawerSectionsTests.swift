import Foundation
import Testing

@testable import YakKit

private func makeContent(
    _ list: ThreadList, filter: ThreadListFilter = .mine, search: String? = nil, expanded: [String: Bool] = [:],
    unsent: [OutboxItem] = []
) -> DrawerContent {
    DrawerSections.make(list: list, filter: filter, search: search, expanded: expanded, unsentNewThreads: unsent)
}

private func rows(_ content: DrawerContent, _ kind: DrawerSection.Kind) -> [DrawerRow] {
    guard case .sections(let sections) = content else { return [] }
    return sections.first { $0.kind == kind }?.rows ?? []
}

private func row(
    _ id: String, status: String = "stopped", reason: String? = nil, group: String = "today",
    children: [ThreadRow] = []
) -> ThreadRow {
    ThreadRow(
        id: id, title: "Title \(id)", repo: "example/api", status: ThreadRowStatus(rawValue: status),
        needsYouReason: reason.map { NeedsYouReason(rawValue: $0) }, isPrivate: false, isShared: false,
        isUnattended: false, isStarred: false, canDelete: true, group: ThreadRowGroup(rawValue: group),
        children: children)
}

private func list(
    needsYou: [NeedsYouItem] = [], threads: [ThreadRow] = [], hasAnyThreads: Bool = true
) -> ThreadList {
    ThreadList(
        starred: [], needsYou: needsYou, threads: threads, needsYouCount: needsYou.count,
        hasAnyThreads: hasAnyThreads)
}

@Suite struct DrawerSectionsTests {
    @Test func aChildShowsItsStep() throws {
        let list = try decoded(ThreadList.self, "scenario-overview-thread-list")
        let content = makeContent(list, expanded: ["thread:s7ackr00": true])
        let all = rows(content, .today) + rows(content, .needsYou)
        let working = try #require(all.first { $0.id == "thread:b4ckf111" })
        let stopped = try #require(all.first { $0.id == "thread:q7adapt0" })
        #expect(working.subtitle == .step("Writing the backfill script"))
        #expect(stopped.subtitle == .status(.stopped))
    }

    @Test func buildsTheSectionsInOrder() throws {
        let list = try decoded(ThreadList.self, "scenario-thread-list")
        guard case .sections(let sections) = makeContent(list) else {
            Issue.record("sections")
            return
        }
        #expect(sections.map(\.kind) == [.needsYou, .today])
        #expect(sections[0].rows.map(\.id) == ["thread:d0e515ef", "task:k3m9p2qa"])
        #expect(
            sections[0].rows[0].subtitle == .serverLabel("Asked you a question")
                && sections[0].rows[0].indicator == .needsYou)
        #expect(
            sections[0].rows[1].target == .task("k3m9p2qa")
                && sections[0].rows[1].subtitle == .serverLabel("Waiting for your co-owner decision"))
    }

    @Test func nestsChildrenUnderTheirParentOnly() throws {
        let list = try decoded(ThreadList.self, "scenario-thread-list")
        let collapsed = rows(makeContent(list), .today)
        #expect(collapsed.map(\.id) == ["thread:a1b2c3d4"])
        #expect(
            collapsed[0].childCount == 1 && collapsed[0].subtitle == .children(total: 1, working: 0)
                && !collapsed[0].isExpanded)
        let open = rows(makeContent(list, expanded: ["thread:a1b2c3d4": true]), .today)
        #expect(open.map(\.id) == ["thread:a1b2c3d4", "thread:e5f6g7h8"] && open[1].depth == 1 && open[1].isPrivate)
    }

    @Test func aRunningOrNeedyChildExpandsItsParent() {
        let parent = row("aaaaaaaa", status: "running", children: [row("bbbbbbbb", status: "running")])
        #expect(DrawerSections.isExpandedByDefault(parent))
        #expect(rows(makeContent(list(threads: [parent])), .today).map(\.id) == ["thread:aaaaaaaa", "thread:bbbbbbbb"])
        #expect(rows(makeContent(list(threads: [parent]), expanded: ["thread:aaaaaaaa": false]), .today).count == 1)
        let needy = row("cccccccc", children: [row("dddddddd", reason: "question")])
        #expect(DrawerSections.isExpandedByDefault(needy))
        let quiet = row("eeeeeeee", children: [row("ffffffff", status: "closed")])
        #expect(!DrawerSections.isExpandedByDefault(quiet))
    }

    @Test func emptyAndNoResults() {
        let empty = list(hasAnyThreads: true)
        #expect(makeContent(empty) == .empty(othersHaveThreads: true))
        #expect(makeContent(empty, filter: .everyone) == .empty(othersHaveThreads: false))
        #expect(makeContent(empty, search: "  webhook retry ") == .noResults(search: "webhook retry", canWiden: true))
        #expect(makeContent(empty, filter: .everyone, search: "x") == .noResults(search: "x", canWiden: false))
        #expect(makeContent(empty, search: "   ") == .empty(othersHaveThreads: true))
    }

    @Test func unsentNewThreadsComeFirst() {
        let draft = NewThreadDraft(repo: "example/api", mode: "task", repositories: [], claudeAccountId: nil)
        let item = OutboxItem(
            id: UUID(), target: .newThread(draft), text: "Fix the import\nmore", model: nil, delivery: .steer,
            attachments: [], state: .waiting, createdAt: Date(timeIntervalSince1970: 1_800_000_000))
        let content = makeContent(list(threads: [row("aaaaaaaa")]), unsent: [item])
        guard case .sections(let sections) = content else {
            Issue.record("sections")
            return
        }
        #expect(sections.map(\.kind) == [.notSentYet, .today])
        let first = sections[0].rows[0]
        #expect(first.title == "Fix the import" && first.subtitle == .unsent(.waiting) && first.indicator == .notSent)
        #expect(first.id == "outbox:\(item.id.uuidString.lowercased())")
    }

    @Test func unknownGroupsAndKindsDoNotDisappear() {
        let content = makeContent(
            list(
                needsYou: [.unknown(type: "observation"), .thread(row("aaaaaaaa", reason: "blocked"))],
                threads: [row("bbbbbbbb", group: "next_month")]))
        #expect(rows(content, .needsYou).map(\.id) == ["thread:aaaaaaaa"])
        #expect(rows(content, .older).map(\.id) == ["thread:bbbbbbbb"])
    }

    @Test func indicatorsFollowReasonThenStatus() {
        let content = makeContent(
            list(
                threads: [
                    row("aaaaaaaa", status: "running", reason: "unseen"),
                    row("bbbbbbbb", status: "waiting_for_repository"),
                    row("cccccccc", status: "mystery"),
                ]))
        #expect(rows(content, .today).map(\.indicator) == [.unseen, .waiting, .other])
    }

    @Test(arguments: [
        (30, RelativeAge.Value.now), (120, .minutes(2)), (3 * 3600, .hours(3)), (2 * 86400, .days(2)), (-50, .now),
    ])
    func relativeAges(secondsAgo: Int, expected: RelativeAge.Value) {
        let now = Date(timeIntervalSince1970: 1_800_000_000)
        #expect(RelativeAge.value(from: now.addingTimeInterval(TimeInterval(-secondsAgo)), now: now) == expected)
    }

    @Test func oldDatesStayDates() {
        let now = Date(timeIntervalSince1970: 1_800_000_000)
        let old = now.addingTimeInterval(-8 * 86400)
        #expect(RelativeAge.value(from: old, now: now) == .date(old))
    }

    @Test func parsesServerDates() {
        #expect(RelativeAge.parse("2026-11-15T09:30:00Z") != nil)
        #expect(RelativeAge.parse("2026-11-15T09:30:00.123456Z") != nil)
        #expect(RelativeAge.parse("yesterday") == nil)
        #expect(RelativeAge.parse(nil) == nil)
    }
}
