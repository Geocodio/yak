import Foundation
import Testing

@testable import YakKit

private func decodeList(_ json: String) throws -> ThreadList {
    try JSONDecoder().decode(ThreadList.self, from: Data(json.utf8))
}

private let row =
    #"{"id":"a1","title":"T","repo":"example/web","status":"stopped","isPrivate":false,"isShared":false,"isUnattended":false,"isStarred":false,"canDelete":true,"group":"today","children":[]"#

@Suite struct ThreadListReviewDecodingTests {
    @Test func theReviewFixtureKeepsEveryField() throws {
        let list = try JSONDecoder().decode(ThreadList.self, from: Fixtures.data("threads-review"))
        #expect(list.reviewCount == 2 && !list.needsGitHubLink && list.gitHubLinkPath == "/settings/profile")
        #expect(list.starred.isEmpty && list.needsYou.isEmpty && list.threads.isEmpty)
        #expect(list.reviewGroups.map(\.key).prefix(2) == ["back_to_you", "not_reviewed"])
        #expect(list.reviewGroups.map(\.label).prefix(2) == ["Back to you", "Not reviewed"])
        let first = try #require(list.reviewGroups[0].rows.first)
        #expect(first.title == "Retry failed uploads" && first.repo == "example/web")
        let review = try #require(first.review)
        #expect(review.hasNewCommitsSinceApproval == false && review.otherApprovals == 0)
        let pull = try #require(review.pullRequests.first)
        #expect(pull.number == 1 && pull.url == "https://github.com/example/web/pull/1")
        #expect(pull.repository == "example/web" && pull.isDraft && !pull.approvedByYou)
        #expect(pull.mergeability == "conflicts" && pull.checks == "failing")
        let second = try #require(list.reviewGroups[1].rows.first?.review?.pullRequests.first)
        #expect(!second.isDraft && second.mergeability == "no_conflicts" && second.checks == "passing")
        let facets = try #require(list.facets)
        #expect(facets.sources.first == ThreadFacetOption(key: "dashboard", label: "Dashboard", count: 4))
        #expect(facets.starters.first == ThreadFacetOption(key: "user:1", label: "Test person", count: 4))
    }

    @Test func everyReviewGroupOfTheFixtureKeepsItsRowsAndPullRequests() throws {
        let list = try JSONDecoder().decode(ThreadList.self, from: Fixtures.data("threads-review"))
        #expect(list.reviewGroups.map(\.key) == ["back_to_you", "not_reviewed", "with_yak", "approved"])
        #expect(list.reviewGroups.map(\.label) == ["Back to you", "Not reviewed", "With Yak", "Approved by you"])
        #expect(
            list.reviewGroups.map { $0.rows.map(\.title) } == [
                ["Retry failed uploads"], ["Tidy the README"], ["Add a health check"], ["Speed up the importer"],
            ])
        let withYak = try #require(list.reviewGroups[2].rows.first?.review)
        #expect(withYak.pullRequests.map(\.number) == [1] && withYak.pullRequests[0].checks == nil)
        #expect(withYak.pullRequests[0].mergeability == "unknown" && !withYak.pullRequests[0].approvedByYou)
        let approved = try #require(list.reviewGroups[3].rows.first?.review)
        #expect(approved.pullRequests[0].approvedByYou && approved.pullRequests[0].checks == "pending")
        #expect(approved.otherApprovals == 1 && !approved.hasNewCommitsSinceApproval)
        #expect(list.reviewGroups.allSatisfy { $0.rows.allSatisfy { $0.review != nil } })
    }

    @Test func aReviewRowIsFoundByIdForTheOpenedThreadsTitle() {
        func row(_ id: String, _ title: String) -> ThreadRow {
            ThreadRow(
                id: id, title: title, repo: "example/web", status: .stopped, isPrivate: false, isShared: false,
                isUnattended: false, isStarred: false, canDelete: true, group: .today, children: [])
        }
        let list = ThreadList(
            starred: [], needsYou: [], threads: [row("t1", "Mine")], needsYouCount: 0, hasAnyThreads: true,
            reviewGroups: [
                ReviewGroup(key: "back_to_you", label: "Back to you", rows: [row("r1", "Retry failed uploads")]),
                ReviewGroup(key: "approved", label: "Approved by you", rows: [row("r2", "Speed up the importer")]),
            ])
        #expect(list.title(ofThread: "r2") == "Speed up the importer")
        #expect(list.title(ofThread: "r1") == "Retry failed uploads" && list.title(ofThread: "t1") == "Mine")
        #expect(list.title(ofThread: "nobody") == nil)
    }

    @Test func theThreadsFixtureCarriesTheNewKeys() throws {
        let list = try JSONDecoder().decode(ThreadList.self, from: Fixtures.data("threads"))
        #expect(list.reviewCount == 0 && list.needsGitHubLink && list.gitHubLinkPath == "/settings/profile")
        #expect(list.reviewGroups.isEmpty && list.facets?.sources.first?.label == "Dashboard")
    }

    @Test func anOlderServersListMeansNoReviewAndNoFacets() throws {
        let list = try decodeList(#"{"needsYou":[],"threads":[],"needsYouCount":0,"hasAnyThreads":true}"#)
        #expect(list.reviewCount == 0 && !list.needsGitHubLink && list.gitHubLinkPath == nil)
        #expect(list.reviewGroups.isEmpty && list.facets == nil)
    }

    @Test func anUnknownGroupKeepsItsLabelAndBadValuesAreKept() throws {
        let json = """
            {"needsYou":[],"threads":[],"needsYouCount":0,"hasAnyThreads":true,"reviewCount":"x",
             "reviewGroups":[
              {"key":"waiting_on_ci","label":"Waiting on CI","rows":[
                \(row),"review":{"pullRequests":[
                  {"number":7,"url":"u","repository":"example/web","mergeability":"mystery","checks":"flaky"},
                  {"url":"no number"}],"otherApprovals":2}},
                {"broken":true}]},
              {"label":"no key"}]}
            """
        let list = try decodeList(json)
        #expect(list.reviewCount == 0)
        #expect(list.reviewGroups.count == 1)
        let group = list.reviewGroups[0]
        #expect(group.key == "waiting_on_ci" && group.label == "Waiting on CI" && group.rows.count == 1)
        let review = try #require(group.rows[0].review)
        #expect(review.pullRequests.count == 1 && review.otherApprovals == 2)
        #expect(review.pullRequests[0].mergeability == "mystery" && review.pullRequests[0].checks == "flaky")
    }

    @Test func aReviewListSurvivesTheSavedCopy() throws {
        let list = try JSONDecoder().decode(ThreadList.self, from: Fixtures.data("threads-review"))
        let again = try JSONDecoder().decode(ThreadList.self, from: JSONEncoder().encode(list))
        #expect(again == list)
    }

    @Test func theReviewGroupsBecomeSectionsWithApprovedFolded() throws {
        let json = """
            {"needsYou":[],"threads":[],"needsYouCount":0,"hasAnyThreads":true,"reviewCount":1,"reviewGroups":[
             {"key":"back_to_you","label":"Back to you","rows":[\(row)}]},
             {"key":"not_reviewed","label":"Not reviewed","rows":[]},
             {"key":"approved","label":"Approved by you","rows":[\(row.replacingOccurrences(of: "a1", with: "a2"))}]}]}
            """
        let list = try decodeList(json)
        guard
            case .sections(let sections) = DrawerSections.make(
                list: list, filter: .review, search: nil, expanded: [:], unsentNewThreads: [])
        else {
            Issue.record("no sections")
            return
        }
        #expect(sections.map(\.count) == [1, 1])
        #expect(sections[0].rows.count == 1 && !sections[0].isCollapsible)
        #expect(sections[1].isCollapsed && sections[1].rows.isEmpty && sections[1].count == 1)
        guard
            case .sections(let opened) = DrawerSections.make(
                list: list, filter: .review, search: nil, expanded: [:], unsentNewThreads: [],
                openedGroups: ["approved"])
        else {
            Issue.record("no sections")
            return
        }
        #expect(!opened[1].isCollapsed && opened[1].rows.count == 1)
    }
}

@Suite struct ThreadTabsTests {
    @Test func theServerFixtureListsItsFilters() throws {
        let me = try JSONDecoder().decode(Me.self, from: Fixtures.data("me"))
        #expect(me.threadFilterWords == ["mine", "yak", "everyone", "closed", "review"])
        #expect(ThreadTabs(serverFilters: me.threadFilterWords).tabs == [.mine, .review, .everyone, .closed])
    }

    @Test func anOlderServersMeHasNoFilterList() throws {
        var object = try #require(
            try JSONSerialization.jsonObject(with: Fixtures.data("me")) as? [String: Any])
        object["threadFilters"] = nil
        let me = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
        #expect(me.threadFilterWords == nil)
        object["threadFilters"] = "nonsense"
        let odd = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
        #expect(odd.threadFilterWords == nil)
        object["threadFilters"] = ["mine", 7, "review", NSNull()]
        let mixed = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
        #expect(mixed.threadFilterWords == ["mine", "review"])
        #expect(ThreadTabs(serverFilters: odd.threadFilterWords).tabs == [.mine, .everyone])
    }

    @Test func aListedServerOffersTheTabsInTheWebsOrder() {
        let tabs = ThreadTabs(serverFilters: ["closed", "review", "everyone", "mine", "yak", "future"])
        #expect(tabs.tabs == [.mine, .review, .everyone, .closed] && tabs.supportsFacets)
        #expect(ThreadTabs(serverFilters: ["mine", "everyone", "closed"]).tabs == [.mine, .everyone, .closed])
        #expect(ThreadTabs(serverFilters: []).tabs == [.mine])
    }

    @Test func anOlderServerOffersWhatTheAppOfferedBefore() {
        let tabs = ThreadTabs(serverFilters: nil)
        #expect(tabs.tabs == [.mine, .everyone] && !tabs.supportsFacets)
        #expect(tabs.resolved(.review) == .mine && tabs.resolved(.closed) == .mine)
    }

    @Test func neverAsksForAFilterTheServerDidNotList() {
        let older = ThreadTabs(serverFilters: nil)
        #expect(older.request(filter: .review, starters: []).filter == .mine)
        let yak = older.request(filter: .mine, starters: ["yak"])
        #expect(yak.filter == .yak && yak.starters.isEmpty)
        #expect(older.request(filter: .everyone, starters: ["yak"]).filter == .everyone)
        let current = ThreadTabs(serverFilters: ["mine", "everyone"])
        #expect(current.request(filter: .closed, starters: ["yak"]).filter == .mine)
        #expect(current.request(filter: .mine, starters: ["yak"]).starters == ["yak"])
    }

    @Test func anOlderServerOffersStartedByYakOnlyOnMine() {
        let older = ThreadTabs(serverFilters: nil)
        #expect(older.offersStartedByYak(on: .mine) && !older.offersStartedByYak(on: .everyone))
        #expect(ThreadTabs(serverFilters: ["mine", "everyone"]).offersStartedByYak(on: .everyone))
        let carried = ThreadListSelection(filter: .everyone, sources: ["slack"], starters: ["yak"])
        #expect(older.normalized(carried) == ThreadListSelection(filter: .everyone))
        let onMine = ThreadListSelection(filter: .mine, starters: ["yak"])
        #expect(older.normalized(onMine) == onMine)
        #expect(ThreadTabs(serverFilters: ["mine"]).normalized(carried) == carried)
    }

    @Test func aCountKeepsToThreeDigits() {
        #expect(ThreadTabs.badgeText(5) == "5" && ThreadTabs.badgeText(99) == "99")
        #expect(ThreadTabs.badgeText(100) == "99+" && ThreadTabs.badgeText(1234) == "99+")
    }

    @Test func theGitHubLinkOpensOnTheInstanceOnly() {
        let instance = URL(string: "https://yak.example.com")!
        #expect(
            ReviewLink.url(path: "/settings/profile", instance: instance)?.absoluteString
                == "https://yak.example.com/settings/profile")
        #expect(ReviewLink.url(path: "/settings/profile?tab=github", instance: instance)?.query == "tab=github")
        #expect(ReviewLink.url(path: "settings/profile", instance: instance) == nil)
        #expect(ReviewLink.url(path: "//evil.example.net/x", instance: instance) == nil)
        #expect(ReviewLink.url(path: "https://evil.example.net/x", instance: instance) == nil)
        #expect(ReviewLink.url(path: nil, instance: instance) == nil)
        #expect(ReviewLink.url(path: "/settings/profile", instance: URL(string: "http://127.0.0.1:1")!) == nil)
    }

    @Test func theOldChipsSavedChoiceMigratesToMineWithTheYakStarter() {
        #expect(ThreadListSelection.migrated(.yak) == ThreadListSelection(filter: .mine, starters: ["yak"]))
        #expect(ThreadListSelection.migrated(.closed) == ThreadListSelection(filter: .closed))
    }

    @Test func theSheetListsWhatWasCountedThenWhatIsPickedButNotCounted() {
        let options = [
            ThreadFacetOption(key: "user:1", label: "Test person", count: 3),
            ThreadFacetOption(key: "yak", label: "Yak", count: 2),
        ]
        let choices = ThreadTabs.choices(from: options, picked: ["yak", "name:gone"], viewerKey: "user:1")
        #expect(choices.map(\.role) == [.viewer, .yak, .person])
        #expect(choices.map(\.isPicked) == [false, true, true])
        #expect(choices.last?.count == 0)
    }
}

@Suite struct ThreadListRequestTests {
    @Test func theRequestCarriesTheTabSourcesStartersAndSearch() {
        let endpoint = Endpoint<ThreadList>.threads(
            filter: .review, search: " retry ", sources: ["slack", "sentry"], starters: ["yak"])
        let pairs = endpoint.query.map { "\($0.name)=\($0.value ?? "")" }
        #expect(pairs == ["filter=review", "sources[]=slack", "sources[]=sentry", "starters[]=yak", "search=retry"])
        #expect(Endpoint<ThreadList>.threads(filter: .mine, search: nil).query.map(\.name) == ["filter"])
    }
}
