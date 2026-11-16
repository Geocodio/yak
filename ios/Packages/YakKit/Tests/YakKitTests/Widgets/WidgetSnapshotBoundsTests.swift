import Foundation
import Testing

@testable import YakKit

struct WidgetSnapshotBoundsTests {
    private let now = Date(timeIntervalSince1970: 0)

    private func row(
        _ id: String, title: String = "Title", status: ThreadRowStatus = .running, children: [ThreadRow] = []
    )
        -> ThreadRow
    {
        ThreadRow(
            id: id, title: title, repo: "example/api", status: status, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: true, group: .today, children: children)
    }

    private func list(_ threads: [ThreadRow], count: Int = 0) -> ThreadList {
        ThreadList(starred: [], needsYou: [], threads: threads, needsYouCount: count, hasAnyThreads: true)
    }

    @Test func boundsCutFlattenAndClamp() {
        #expect(WidgetText.bounded("a\nb\tc\u{0}d", limit: 200) == "a b c d")
        let cut = WidgetText.bounded(String(repeating: "x", count: 300), limit: 200)
        #expect(cut.count == 200 && cut.hasSuffix("…"))
        #expect(WidgetText.bounded(String(repeating: "\u{2028}", count: 10_000), limit: 200) == "")
        #expect(WidgetText.bounded("  padded  ", limit: 200) == "padded")
        #expect(WidgetText.clamped(-5) == 0 && WidgetText.clamped(1_000_000) == 9999)
        #expect(WidgetText.countLabel(0) == "0" && WidgetText.countLabel(99) == "99")
        #expect(WidgetText.countLabel(100) == "99+" && WidgetText.countLabel(Int.max) == "99+")
    }

    @Test func formatCharactersAndCombiningFloodsAreBounded() {
        #expect(WidgetText.bounded("a\u{202E}b\u{2066}c\u{200B}d", limit: 200) == "abcd")
        let flood = "e" + String(repeating: "\u{0301}", count: 100_000)
        #expect(WidgetText.bounded(flood, limit: 200).unicodeScalars.count <= 200 * 4)
        #expect(WidgetText.bounded(String(repeating: flood, count: 5), limit: 200).unicodeScalars.count <= 200 * 4)
    }

    @Test func aPrivateStepNeverReachesTheSnapshot() throws {
        let snapshot = WidgetSnapshot.make(
            from: try decoded(ThreadList.self, "scenario-widgets-thread-list-busy"), instanceName: "Example Yak",
            now: now)
        let bytes = String(decoding: try JSONEncoder().encode(snapshot), as: UTF8.self)
        #expect(!bytes.contains("Private task about payroll") && !bytes.contains("Reading payroll.csv"))
        let privateRunning = try #require(snapshot.running.first { $0.id == "r1000002" })
        #expect(privateRunning.title == nil && privateRunning.detail == nil && privateRunning.isPrivate)
        #expect(snapshot.running.first { $0.id == "r1000001" }?.detail == "Running the test suite")
        let privateTask = try #require(snapshot.needsYou.first { $0.id == "p9p9p9p9" })
        #expect(privateTask.title == nil && privateTask.detail == "Waiting for your co-owner decision")
        #expect(snapshot.needsYou[0].detail == "Asked you a question")
    }

    @Test func listsStayWithinTheirBounds() throws {
        let snapshot = WidgetSnapshot.make(
            from: try decoded(ThreadList.self, "scenario-widgets-thread-list-busy"), instanceName: "Example Yak",
            now: now)
        #expect(snapshot.needsYou.count == 4 && snapshot.running.count == 8 && snapshot.needsYouCount == 4)
        let empty = WidgetSnapshot.make(
            from: try decoded(ThreadList.self, "scenario-widgets-thread-list-empty"), instanceName: "Example Yak",
            now: now)
        #expect(empty.needsYou.isEmpty && empty.running.isEmpty && empty.needsYouCount == 0)
    }

    @Test func aDeeplyNestedListDoesNotRecurse() {
        var nested = row("deep-400")
        for level in stride(from: 399, through: 0, by: -1) { nested = row("deep-\(level)", children: [nested]) }
        let snapshot = WidgetSnapshot.make(from: list([nested]), instanceName: "Example Yak", now: now)
        #expect(snapshot.running.map(\.id) == ["deep-0", "deep-1", "deep-2"])

        var rowsRead = 0
        let wide = (0..<10_000).map { row("s\($0)", status: .stopped) }
        _ = WidgetSnapshot.make(from: list(wide), instanceName: "Example Yak", now: now, rowsRead: &rowsRead)
        #expect(rowsRead == 500)
    }

    @Test func hostileTitlesAndCountsAreBounded() {
        let hostile = row("h1", title: String(repeating: "t", count: 1_000_000))
        let snapshot = WidgetSnapshot.make(
            from: list([hostile], count: -3), instanceName: String(repeating: "n", count: 500), now: now)
        #expect(snapshot.running[0].title?.count == 200 && snapshot.needsYouCount == 0)
        #expect(snapshot.instanceName.count == 60)
    }

    @Test func anOldSnapshotFileStillDecodes() throws {
        let old = #"{"kind":"thread","id":"a","title":"T","isPrivate":false}"#
        let item = try JSONDecoder().decode(WidgetItem.self, from: Data(old.utf8))
        #expect(item.detail == nil && item.lastActivityAt == nil && item.needsYouReason == nil)
        let wrong = #"{"kind":"thread","id":"a","title":"T","isPrivate":false,"needsYouReason":42,"detail":[1]}"#
        let wrongItem = try JSONDecoder().decode(WidgetItem.self, from: Data(wrong.utf8))
        #expect(wrongItem.needsYouReason == nil && wrongItem.detail == nil)
        let long =
            #"{"kind":"thread","id":"a","title":"T","isPrivate":false,"needsYouReason":"\#(String(repeating: "r", count: 10_000))"}"#
        #expect(try JSONDecoder().decode(WidgetItem.self, from: Data(long.utf8)).needsYouReason?.count == 32)
    }

    @Test func reasonsAreCarried() throws {
        let snapshot = WidgetSnapshot.make(
            from: try decoded(ThreadList.self, "scenario-thread-list"), instanceName: "Example Yak", now: now)
        #expect(snapshot.needsYou[0].needsYouReason == "question")
        #expect(snapshot.needsYou[1].needsYouReason == "co_owner")
        #expect(snapshot.needsYou[0].lastActivityAt == Date(timeIntervalSince1970: 1_794_735_000))
        #expect(snapshot.running.allSatisfy { $0.needsYouReason == nil })
        let busy = WidgetSnapshot.make(
            from: try decoded(ThreadList.self, "scenario-widgets-thread-list-busy"), instanceName: "Example Yak",
            now: now)
        let privateTask = busy.needsYou.first { $0.id == "p9p9p9p9" }
        #expect(privateTask?.needsYouReason == "co_owner" && privateTask?.title == nil)
    }
}
