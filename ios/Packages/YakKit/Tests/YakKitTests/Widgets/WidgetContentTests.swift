import Foundation
import Testing

@testable import YakKit

struct WidgetContentTests {
    private let now = Date(timeIntervalSince1970: 1_800_000_000)
    private let instance = Instance(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test Person",
        lastUsedAt: Date(timeIntervalSince1970: 0))

    private func snapshot(_ name: String, updatedAt: Date? = nil) throws -> WidgetSnapshot {
        WidgetSnapshot.make(
            from: try decoded(ThreadList.self, name), instanceName: "Example Yak", now: updatedAt ?? now)
    }

    private func needsYou(_ snapshot: WidgetSnapshot?, _ size: WidgetFamilySize, state: WidgetState = .content)
        -> NeedsYouWidgetContent
    {
        NeedsYouWidgetContent.make(snapshot: snapshot, state: state, instance: instance, size: size, now: now)
    }

    private func running(_ snapshot: WidgetSnapshot?, _ size: WidgetFamilySize) -> RunningWidgetContent {
        RunningWidgetContent.make(snapshot: snapshot, state: .content, instance: instance, size: size, now: now)
    }

    private func item(
        _ id: String, kind: WidgetItem.Kind = .thread, title: String? = "Title", isPrivate: Bool = false,
        detail: String? = nil, reason: String? = nil
    ) -> WidgetItem {
        WidgetItem(kind: kind, id: id, title: title, isPrivate: isPrivate, detail: detail, needsYouReason: reason)
    }

    private func custom(needsYou: [WidgetItem] = [], running: [WidgetItem] = [], count: Int = 0, at: Date? = nil)
        -> WidgetSnapshot
    {
        WidgetSnapshot(
            instanceName: "Example Yak", needsYouCount: count, needsYou: needsYou, running: running,
            updatedAt: at ?? now)
    }

    @Test func smallShowsTheCountAndTheFirstItem() throws {
        let content = needsYou(try snapshot("scenario-thread-list"), .small)
        #expect(content.countLabel == "2" && content.count == 2 && content.rows.count == 1)
        let row = try #require(content.rows.first)
        #expect(row.title == .text("Retry failed rows when the import times out"))
        #expect(row.detail == "Asked you a question" && row.reason == .question)
        #expect(
            row.link.absoluteString == "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515ef")
        #expect(content.link == row.link && content.instanceName == "Example Yak")
        #expect(needsYou(try snapshot("scenario-thread-list"), .accessoryCircular).rows.count == 1)
    }

    @Test func mediumShowsThreeRowsAndATaskLink() throws {
        let busy = needsYou(try snapshot("scenario-widgets-thread-list-busy"), .medium)
        #expect(busy.rows.count == 3)
        let content = needsYou(try snapshot("scenario-thread-list"), .medium)
        #expect(content.rows.count == 2)
        let task = try #require(content.rows.last)
        #expect(task.title == .task("Review the import job") && task.id == "task:k3m9p2qa")
        #expect(task.link.absoluteString == "yak://task?instance=https%3A%2F%2Fyak.example.com&id=k3m9p2qa")
    }

    @Test func privateItemsShowAPrivateThread() throws {
        let content = needsYou(try snapshot("scenario-widgets-thread-list-busy"), .medium)
        let all =
            try needsYou(snapshot("scenario-widgets-thread-list-busy"), .medium).rows
            + (try needsYou(snapshot("scenario-widgets-thread-list-busy"), .small).rows)
        #expect(all.count == 4)
        let privateTask = custom(needsYou: [item("p9p9p9p9", kind: .task, title: nil, isPrivate: true, detail: "Label")]
        )
        let row = try #require(needsYou(privateTask, .small).rows.first)
        #expect(row.title == .privateThread && row.detail == "Label")
        let described =
            String(describing: running(try snapshot("scenario-widgets-thread-list-busy"), .medium))
            + String(describing: content)
        #expect(!described.contains("Private task about payroll") && !described.contains("Reading payroll.csv"))
        // a public item without a title is also shown as private
        #expect(needsYou(custom(needsYou: [item("abcd1234", title: nil)]), .small).rows.first?.title == .privateThread)
    }

    @Test func dotsFollowTheReason() {
        let snapshot = custom(
            needsYou: [
                item("aaaaaaa1", reason: "question"), item("aaaaaaa2", reason: "blocked"),
                item("aaaaaaa3", kind: .task, reason: "co_owner"), item("aaaaaaa4", reason: "unseen"),
                item("aaaaaaa5", title: nil, isPrivate: true, reason: "unseen"),
                item("aaaaaaa6", reason: "something_new"),
            ], running: [item("bbbbbbb1")], count: 6)
        let rows = NeedsYouWidgetContent.make(
            snapshot: snapshot, state: .content, instance: instance, size: .medium, now: now
        ).rows
        #expect(rows.map(\.dot) == [.accent, .accent, .accent])
        let all = (0..<6).map { index in
            needsYou(custom(needsYou: Array(snapshot.needsYou[index...])), .small).rows[0].dot
        }
        #expect(all == [.accent, .accent, .accent, .info, .info, .accent])
        #expect(running(snapshot, .small).rows.first?.dot == .success)
        #expect(running(snapshot, .small).rows.first?.reason == nil)
    }

    @Test func aRowWithoutALabelKeepsItsReason() throws {
        let content = needsYou(custom(needsYou: [item("aaaaaaa1", reason: "unseen")], count: 1), .small)
        let row = try #require(content.rows.first)
        #expect(row.detail == nil && row.reason == .unseen)
    }

    @Test func runningCountsAndSteps() throws {
        let content = running(try snapshot("scenario-widgets-thread-list-busy"), .medium)
        #expect(content.countLabel == "8+" && content.count == 8 && content.rows.count == 3)
        #expect(
            content.rows[0].detail == "Running the test suite" && content.rows[0].title == .text("Run the test suite"))
        #expect(content.rows[1].title == .privateThread && content.rows[1].detail == nil)
        #expect(running(custom(running: [item("bbbbbbb1"), item("bbbbbbb2")]), .small).countLabel == "2")
        #expect(running(try snapshot("scenario-widgets-thread-list-busy"), .small).rows.count == 1)
    }

    @Test func staleAfterOneHour() {
        let fresh = needsYou(custom(at: now.addingTimeInterval(-59 * 60)), .small)
        let old = needsYou(custom(at: now.addingTimeInterval(-61 * 60)), .small)
        #expect(!fresh.isStale && old.isStale && old.updatedAt == now.addingTimeInterval(-61 * 60))
    }

    @Test func everyStateHasNoRows() throws {
        let full = try snapshot("scenario-widgets-thread-list-busy")
        let states: [WidgetState] = [
            .noInstance, .instanceMissing, .needsPairing(host: "yak.example.com"),
            .notLoaded(host: "yak.example.com"),
        ]
        for state in states {
            let content = needsYou(full, .medium, state: state)
            #expect(content.rows.isEmpty && content.count == 0 && content.countLabel == "0" && content.link == nil)
            #expect(content.state == state && content.updatedAt == nil && !content.isStale)
            let other = RunningWidgetContent.make(snapshot: full, state: state, instance: nil, size: .medium, now: now)
            #expect(other.rows.isEmpty && other.count == 0 && other.instanceName == "")
        }
        #expect(needsYou(nil, .small).rows.isEmpty)
    }

    @Test func aHostileIdHasNoLink() {
        let hostile = custom(
            needsYou: [item("d0e515ef&x=1"), item("../../a"), item("abcd123"), item("abcd12345"), item("abcd123é")],
            count: 5)
        let content = needsYou(hostile, .medium)
        #expect(content.rows.isEmpty && content.count == 5 && content.link == nil)
        let kept = needsYou(custom(needsYou: [item("d0e515ef&x=1"), item("good0001")], count: 2), .small)
        #expect(kept.rows.map(\.id) == ["thread:good0001"])
    }

    @Test func emptyIsZero() throws {
        let content = needsYou(try snapshot("scenario-widgets-thread-list-empty"), .medium)
        #expect(content.countLabel == "0" && content.rows.isEmpty && content.link == nil && content.state == .content)
    }
}
