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
    children: [ThreadRow] = [], stackState: String? = nil, step: String? = nil
) -> ThreadRow {
    ThreadRow(
        id: id, title: "Title \(id)", repo: "example/api", status: ThreadRowStatus(rawValue: status),
        needsYouReason: reason.map { NeedsYouReason(rawValue: $0) }, isPrivate: false, isShared: false,
        isUnattended: false, isStarred: false, canDelete: true, group: ThreadRowGroup(rawValue: group),
        children: children, step: step, stackState: stackState)
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

    @Test func aSubThreadRowUsesTheSharedWordsForItsState() throws {
        let parent = row(
            "p0000001", status: "running",
            children: [
                row("c0000001", status: "running"), row("c0000002", status: "closed"),
                row("c0000003", status: "paused"),
            ])
        let content = makeContent(list(threads: [parent]), expanded: ["thread:p0000001": true])
        let all = rows(content, .today)
        #expect(
            all.map(\.subtitle) == [
                .children(total: 3, working: 1), .subThread(.working), .subThread(.stopped), .status(.paused),
            ])
        #expect(all[1].subtitle != .status(.running))
        // A thread that is not a sub-thread keeps its own word.
        let root = makeContent(list(threads: [row("r0000001", status: "closed")]))
        #expect(rows(root, .today).map(\.subtitle) == [.status(.closed)])
    }

    @Test(arguments: [
        ("starting", SubThreadStateStyle.Word.starting), ("queued", .waiting), ("working", .working),
        ("waiting", .needsYou), ("done", .done), ("failed", .failed), ("closed", .stopped),
        ("hologram", .other),
    ])
    func aSubThreadRowWithTheStackStateReadsLikeTheOverview(state: String, word: SubThreadStateStyle.Word) {
        let parent = row(
            "p0000001", status: "running", children: [row("c0000001", status: "stopped", stackState: state)])
        let content = makeContent(list(threads: [parent]), expanded: ["thread:p0000001": true])
        #expect(rows(content, .today).map(\.subtitle).last == .subThread(word))
    }

    @Test func aWorkingSubThreadKeepsItsStepAndAnyOtherStateTheWord() {
        let parent = row(
            "p0000001", status: "running",
            children: [
                row("c0000001", stackState: "working", step: "Writing the migration"),
                row("c0000002", stackState: "done", step: "Old step"),
            ])
        let content = makeContent(list(threads: [parent]), expanded: ["thread:p0000001": true])
        #expect(rows(content, .today).map(\.subtitle).suffix(2) == [.step("Writing the migration"), .subThread(.done)])
    }

    @Test func withoutTheStackStateADrawerRowKeepsItsOldWords() {
        let parent = row(
            "p0000001", status: "running",
            children: [row("c0000001", status: "paused"), row("c0000002", status: "stopped", step: "Step")])
        let content = makeContent(list(threads: [parent]), expanded: ["thread:p0000001": true])
        #expect(rows(content, .today).map(\.subtitle).suffix(2) == [.status(.paused), .step("Step")])
    }

    @Test func theStackStateDecodesAsAStringAndAnUnknownValueIsKept() throws {
        func decode(_ extra: String) throws -> ThreadRow {
            let json = """
                {"id":"a1","title":"T","repo":"example/api","status":"stopped","isPrivate":false,"isShared":false,
                 "isUnattended":false,"isStarred":false,"canDelete":true,"group":"today","children":[]\(extra)}
                """
            return try JSONDecoder().decode(ThreadRow.self, from: Data(json.utf8))
        }
        #expect(try decode(#","stackState":"hologram""#).stackState == "hologram")
        #expect(try decode("").stackState == nil)
        #expect(try decode(#","stackState":5"#).stackState == nil)
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

@Test func parseReadsPlainFractionalAndOffsetTimestamps() {
    let expected = Date(timeIntervalSince1970: 1_794_744_000)
    #expect(RelativeAge.parse("2026-11-15T12:00:00Z") == expected)
    #expect(RelativeAge.parse("2026-11-15T12:00:00.250Z") == expected.addingTimeInterval(0.25))
    #expect(RelativeAge.parse("2026-11-15T13:00:00+01:00") == expected)
    #expect(RelativeAge.parse("not a date") == nil)
    #expect(RelativeAge.parse(nil) == nil)
}

@Suite struct DrawerSearchRowsTests {
    @Test func aThreadResultReadsAsARowWithItsRepositoryLine() {
        let result = SearchResult(
            kind: .thread, id: "a1b2c3d4", title: "Retry uploads", subtitle: "example/web / yak/retry")
        let row = DrawerRow.searchResult(result)
        #expect(row.id == "thread:a1b2c3d4" && row.target == .thread("a1b2c3d4") && row.depth == 0)
        #expect(row.repositoryDetail == "example/web / yak/retry" && row.indicator == .searchResult)
    }

    @Test func aPullRequestWithAThreadOpensThatThread() {
        let result = SearchResult(
            kind: .pullRequest, id: "https://github.com/example/web/pull/1", title: "T", subtitle: "", threadID: "t9")
        #expect(DrawerRow.searchResult(result).target == .thread("t9"))
    }

    @Test func gatheringKeepsRootThreadsAndTasksUnderOneHeadingAndDropsSubThreads() throws {
        let list = try decoded(ThreadList.self, "scenario-overview-thread-list")
        let content = DrawerSections.make(
            list: list, filter: .mine, search: nil, expanded: ["thread:s7ackr00": true], unsentNewThreads: [])
        guard case .sections(let gathered) = content.gathered(as: .savedOnThisPhone) else {
            Issue.record("no section")
            return
        }
        #expect(gathered.map(\.kind) == [.savedOnThisPhone])
        let rows = try #require(gathered.first?.rows)
        #expect(!rows.isEmpty && rows.allSatisfy { $0.depth == 0 && $0.childCount == 0 })
    }

    @Test func gatheringLeavesAMessageAsItIs() {
        #expect(
            DrawerContent.noResults(search: "x", canWiden: false).gathered(as: .loadedThreads)
                == .noResults(search: "x", canWiden: false))
    }

    @Test func aThreadResultThatIsNotLoadedOffersNoStarAndNoCloseOrDelete() {
        let result = SearchResult(kind: .thread, id: "zz", title: "T", subtitle: "example/web / yak/t")
        let row = DrawerRow.searchResult(result)
        #expect(row.isUnloadedSearchResult)
        let menu = ThreadActionMenu.make(.row(row, isOffline: false))
        let kinds = menu.items.map(\.kind)
        #expect(
            !kinds.contains(.star) && !kinds.contains(.unstar) && !kinds.contains(.close) && !kinds.contains(.delete))
        #expect(kinds.contains(.open))
    }
}
