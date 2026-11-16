import Foundation
import Testing

@testable import YakKit

private let instanceID = UUID(uuidString: "11111111-1111-1111-1111-111111111111")!
private let otherID = UUID(uuidString: "22222222-2222-2222-2222-222222222222")!
private let instance = SpotlightInstance(id: instanceID, name: "Example Yak")
private let now = Date(timeIntervalSince1970: 1_800_000_000)

private func row(
    _ id: String, _ title: String = "A title", isPrivate: Bool = false, children: [ThreadRow] = []
) -> ThreadRow {
    ThreadRow(
        id: id, title: title, repo: "example/api", status: .running, isPrivate: isPrivate, isShared: false,
        isUnattended: false, isStarred: false, canDelete: true, group: .today, children: children)
}

private func list(
    starred: [ThreadRow] = [], needsYou: [NeedsYouItem] = [], threads: [ThreadRow] = []
) -> ThreadList {
    ThreadList(
        starred: starred, needsYou: needsYou, threads: threads, needsYouCount: 0, hasAnyThreads: true)
}

private func entries(_ lists: [ThreadList], includePrivate: Bool = false) -> [SpotlightEntry] {
    SpotlightIndexPlan.entries(lists: lists, instance: instance, includePrivate: includePrivate, now: now)
}

private func ids(_ entries: [SpotlightEntry]) -> [String] {
    entries.map { String($0.identifier.split(separator: "/").last ?? "") }
}

struct SpotlightIndexPlanTests {
    @Test func onlyNonPrivateThreadsWithTheInstanceName() throws {
        let result = entries([try decoded(ThreadList.self, "scenario-thread-list")])
        #expect(result.count == 1)
        let entry = try #require(result.first)
        #expect(entry.title == "Retry failed rows when the import times out")
        #expect(entry.instanceName == "Example Yak")
        #expect(entry.domain == instanceID.uuidString)
        #expect(entry.identifier == "\(instanceID.uuidString)/d0e515ef")
        #expect(entry.expiresAt == now.addingTimeInterval(30 * 24 * 60 * 60))
    }

    @Test func aPrivateThreadAndItsSubThreadsStayOut() {
        let grandchild = row("gggggggg", isPrivate: false)
        let child = row("cccccccc", isPrivate: false, children: [grandchild])
        let privateParent = row("pppppppp", isPrivate: true, children: [child])
        #expect(entries([list(threads: [privateParent])]).isEmpty)

        let publicParent = row("aaaaaaaa", children: [row("bbbbbbbb", isPrivate: true)])
        #expect(ids(entries([list(threads: [publicParent])])) == ["aaaaaaaa"])
    }

    @Test func aPublicSubThreadUnderAPrivateParentStaysOutOfEveryList() {
        let child = row("cccccccc")
        let privateParent = row("pppppppp", isPrivate: true, children: [child])
        let first = list(threads: [privateParent])
        let second = list(starred: [child], needsYou: [.thread(child)], threads: [child])
        #expect(entries([first, second]).isEmpty)
        #expect(entries([second, first]).isEmpty)
        #expect(Set(ids(entries([first, second], includePrivate: true))) == ["pppppppp", "cccccccc"])
    }

    @Test func formatCharactersBecomeSpacesInTitles() {
        let result = entries([list(threads: [row("aaaaaaa1", "a\u{200E}b\u{200F}c\u{061C}d\u{200B}e\u{FEFF}f")])])
        #expect(result[0].title == "a b c d e f")
    }

    @Test func theSwitchLetsPrivateTitlesIn() throws {
        let result = entries([try decoded(ThreadList.self, "scenario-thread-list")], includePrivate: true)
        #expect(Set(ids(result)) == ["d0e515ef", "a1b2c3d4", "e5f6g7h8"])
    }

    @Test func entriesAreBoundedAndDeduplicated() {
        func rows(_ count: Int) -> [ThreadRow] { (0..<count).map { row(String(format: "x%07d", $0)) } }
        let big = rows(10_000)
        let one = list(
            starred: big, needsYou: big.map { .thread($0) }, threads: big)
        let result = entries([one, one, one])
        #expect(result.count == 200)
        #expect(Set(result.map(\.identifier)).count == 200)
        #expect(ids(result) == big.prefix(200).map(\.id))
    }

    @Test func deepNestingIsBounded() {
        var chain = row("z0000000")
        for index in (0..<1000).reversed() {
            chain = row(String(format: "n%07d", index), children: [chain])
        }
        #expect(entries([list(threads: [chain])]).count == 17)
    }

    @Test func titlesAreCleanedAndCut() {
        let long = String(repeating: "a", count: 1_000_000)
        let result = entries([
            list(threads: [
                row("aaaaaaa1", long),
                row("aaaaaaa2", "Fix\nthe\u{202E}import\u{0000}job"),
                row("aaaaaaa3", " \n  \t "),
                row("../x", "bad"), row("1234567", "short"), row("123456789", "long"),
            ])
        ])
        #expect(result.count == 2)
        #expect(result[0].title.count == 200)
        #expect(result[0].title.hasSuffix("…"))
        #expect(result[1].title == "Fix the import job")

        let name = SpotlightInstance(id: instanceID, name: String(repeating: "n", count: 300))
        let named = SpotlightIndexPlan.entries(
            lists: [list(threads: [row("aaaaaaa1")])], instance: name, includePrivate: false, now: now)
        #expect(named[0].instanceName.count == 80)
        #expect(named[0].instanceName.hasSuffix("…"))
    }

    @Test func identifiersRoundTripAndHostileOnesGiveNil() throws {
        let paired = try Instance(
            id: instanceID, url: #require(URL(string: "https://yak.example.com")), name: "Example Yak",
            userName: "Test Person", lastUsedAt: now)
        let entry = try #require(entries([try decoded(ThreadList.self, "scenario-thread-list")]).first)
        let link = try #require(SpotlightIndexPlan.link(forIdentifier: entry.identifier, instances: [paired]))
        let components = try #require(URLComponents(url: link, resolvingAgainstBaseURL: false))
        #expect(components.scheme == "yak")
        #expect(components.host == "thread")
        let items = components.queryItems ?? []
        #expect(items.count == 2)
        #expect(items.first { $0.name == "instance" }?.value == "https://yak.example.com")
        #expect(items.first { $0.name == "id" }?.value == "d0e515ef")

        let uuid = instanceID.uuidString
        let hostile = [
            "", "/", uuid, "\(uuid)/", "\(uuid)/a/b", "not-a-uuid/d0e515ef", "\(uuid)/../x",
            "\(uuid)/d0e515ef1", String(repeating: "x", count: 10_000),
            "\(otherID.uuidString)/d0e515ef",
        ]
        for identifier in hostile {
            #expect(SpotlightIndexPlan.link(forIdentifier: identifier, instances: [paired]) == nil)
        }
    }

    private func fullList() throws -> ThreadList { try decoded(ThreadList.self, "scenario-thread-list") }

    @Test func theAppLockKeepsPrivateTitlesOut() throws {
        let active = (instance: instance, lists: [try fullList()])
        let record = SpotlightIndexRecord(indexedDomains: [instanceID], includedPrivate: true)
        let changes = SpotlightIndexPlan.changes(
            record: record, paired: [instanceID], active: active, includePrivate: true, appLockOn: true, now: now)
        #expect(changes.count == 2)
        #expect(changes[0] == .eraseAll)
        guard case .replace(instanceID, let kept) = changes[1] else {
            Issue.record("no replace")
            return
        }
        #expect(ids(kept) == ["d0e515ef"])

        var stored = record
        for change in changes {
            stored = SpotlightIndexPlan.recordingIntent(of: change, includePrivate: false, in: stored)
            stored = SpotlightIndexPlan.record(stored, after: change, succeeded: true, includePrivate: false)
        }
        let again = SpotlightIndexPlan.changes(
            record: stored, paired: [instanceID], active: active, includePrivate: true, appLockOn: true, now: now)
        #expect(again.count == 1)
        guard case .replace = again[0] else {
            Issue.record("no replace")
            return
        }
    }

    @Test func theEffectivePrivateValueFollowsTheAppLock() {
        #expect(SpotlightIndexPlan.allowsPrivateTitles(includePrivate: true, appLockOn: false))
        #expect(!SpotlightIndexPlan.allowsPrivateTitles(includePrivate: true, appLockOn: true))
        #expect(!SpotlightIndexPlan.allowsPrivateTitles(includePrivate: false, appLockOn: false))
    }

    @Test func turningTheSwitchOffErasesBeforeReindexing() throws {
        let record = SpotlightIndexRecord(indexedDomains: [instanceID], includedPrivate: true)
        let changes = SpotlightIndexPlan.changes(
            record: record, paired: [instanceID], active: (instance, [try fullList()]),
            includePrivate: false, appLockOn: false, now: now)
        #expect(changes.count == 2)
        #expect(changes[0] == .eraseAll)
        guard case .replace(_, let kept) = changes[1] else {
            Issue.record("no replace")
            return
        }
        #expect(ids(kept) == ["d0e515ef"])
    }

    @Test func aCrashDuringAPrivateWriteIsErasedLater() throws {
        let privateEntries = entries([try fullList()], includePrivate: true)
        let write = SpotlightChange.replace(domain: instanceID, entries: privateEntries)
        let crashed = SpotlightIndexPlan.recordingIntent(of: write, includePrivate: true, in: .empty)
        for (includePrivate, appLockOn) in [(true, true), (false, false)] {
            let changes = SpotlightIndexPlan.changes(
                record: crashed, paired: [instanceID], active: nil, includePrivate: includePrivate,
                appLockOn: appLockOn, now: now)
            #expect(changes == [.eraseAll])
        }
        let record = SpotlightIndexRecord(indexedDomains: [instanceID])
        #expect(
            SpotlightIndexPlan.recordingIntent(of: .erase(domains: [otherID]), includePrivate: true, in: record)
                == record)
        #expect(SpotlightIndexPlan.recordingIntent(of: .eraseAll, includePrivate: true, in: record) == record)
    }

    @Test func aFailedEraseIsFinishedLater() {
        let indexed = SpotlightIndexRecord(indexedDomains: [instanceID, otherID])
        let removing = SpotlightIndexPlan.recordingRemoval(of: instanceID, in: indexed)
        #expect(removing.pendingErasures == [instanceID])
        let change = SpotlightChange.erase(domains: [instanceID])
        let failed = SpotlightIndexPlan.record(removing, after: change, succeeded: false, includePrivate: false)
        #expect(failed == removing)
        let again = SpotlightIndexPlan.changes(
            record: failed, paired: [otherID], active: nil, includePrivate: false, appLockOn: false, now: now)
        #expect(again == [change])
        let done = SpotlightIndexPlan.record(failed, after: change, succeeded: true, includePrivate: false)
        #expect(done.pendingErasures.isEmpty)
        #expect(done.indexedDomains == [otherID])
    }

    @Test func aDomainWithoutAnInstanceIsErased() {
        let record = SpotlightIndexRecord(indexedDomains: [instanceID, otherID])
        let changes = SpotlightIndexPlan.changes(
            record: record, paired: [otherID], active: nil, includePrivate: false, appLockOn: false, now: now)
        #expect(changes == [.erase(domains: [instanceID])])
    }

    @Test func aHostileRecordIsBounded() throws {
        var unknown = SpotlightIndexRecord.empty
        unknown.pendingEraseAll = true
        #expect(SpotlightIndexRecord.decode(nil) == unknown)
        #expect(SpotlightIndexRecord.decode(Data("garbage".utf8)) == unknown)
        let huge = SpotlightIndexRecord(indexedDomains: Set((0..<10_000).map { _ in UUID() }))
        #expect(SpotlightIndexRecord.decode(huge.encoded()) == unknown)
        let valid = SpotlightIndexRecord(
            indexedDomains: [instanceID], pendingErasures: [otherID], pendingEraseAll: false, includedPrivate: true)
        #expect(SpotlightIndexRecord.decode(valid.encoded()) == valid)
    }

    @Test func anActiveInstanceThatIsNotPairedIsNotIndexed() throws {
        let changes = SpotlightIndexPlan.changes(
            record: .empty, paired: [otherID], active: (instance, [try fullList()]), includePrivate: false,
            appLockOn: false, now: now)
        #expect(changes.isEmpty)
    }
}
