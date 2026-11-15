import Foundation
import Testing

@testable import YakKit

struct IntentRulesTests {
    private let now = Date(timeIntervalSince1970: 1_800_000_000)
    private let instance = Instance(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
        lastUsedAt: Date(timeIntervalSince1970: 0))

    private func snapshot(_ name: String) throws -> WidgetSnapshot {
        WidgetSnapshot.make(from: try decoded(ThreadList.self, name), instanceName: "Example Yak", now: now)
    }

    private func makeCache(files: InMemoryFileStore = InMemoryFileStore()) -> Cache {
        Cache(instance: instance.id, files: files, clock: ManualClock())
    }

    private func cachedThread(private isPrivate: Bool, title: String) throws -> CachedThread {
        let list = try EventList.load("events/simple-turn")
        var data = try JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as! [String: Any]
        var thread = data["thread"] as! [String: Any]
        thread["title"] = title
        thread["visibility"] = isPrivate ? "private" : "shared"
        data["thread"] = thread
        let show = try JSONDecoder().decode(
            ThreadShow.self, from: JSONSerialization.data(withJSONObject: data))
        return CachedThread(
            show: show, events: list.events, lastSequence: list.catchUp.latestSequence, firstLoadedAfter: 0)
    }

    @Test func aPrivateTitleReachesNoSurface() throws {
        let busy = try snapshot("scenario-widgets-thread-list-busy")
        let privateTask = try #require(busy.needsYou.first { $0.isPrivate })
        let reordered = WidgetSnapshot(
            instanceName: busy.instanceName, needsYouCount: busy.needsYouCount,
            needsYou: [privateTask] + busy.needsYou.filter { $0 != privateTask }, running: busy.running,
            updatedAt: busy.updatedAt)
        let privateStrings = ["Private task about payroll", "Reading payroll.csv notes", "Reading payroll.csv"]
        var outputs: [String] = [String(describing: reordered)]
        for size in [WidgetFamilySize.small, .medium, .accessoryRectangular, .accessoryCircular] {
            outputs.append(
                String(
                    describing: NeedsYouWidgetContent.make(
                        snapshot: reordered, state: .content, instance: instance, size: size, now: now)))
            outputs.append(
                String(
                    describing: RunningWidgetContent.make(
                        snapshot: reordered, state: .content, instance: instance, size: size, now: now)))
        }
        let quick = QuickActionItems.make(instance: instance, snapshot: reordered)
        let summary = NeedsYouSummary.make([(instance, reordered, true)])
        let rows = ThreadEntityRows.needsYou(reordered, instance: instance)
        outputs += [String(describing: quick), String(describing: summary), String(describing: rows)]
        for output in outputs {
            for secret in privateStrings { #expect(!output.contains(secret)) }
        }
        #expect(quick.last?.kind == .open(.privateThread))
        #expect(rows.first?.title == .privateThread)
        let running = RunningWidgetContent.make(
            snapshot: reordered, state: .content, instance: instance, size: .medium, now: now)
        #expect(running.rows.contains { $0.title == .privateThread })
    }

    @Test func theSummaryCountsPerInstanceWithoutTitles() throws {
        let other = Instance(
            url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 0))
        let third = Instance(
            url: URL(string: "https://third.example.com")!, name: "Third Yak", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 0))
        func counted(_ count: Int) -> WidgetSnapshot {
            WidgetSnapshot(instanceName: "x", needsYouCount: count, needsYou: [], running: [], updatedAt: now)
        }
        let summary = NeedsYouSummary.make([
            (instance, counted(2), true), (other, counted(1), false), (third, nil, true),
        ])
        #expect(summary.total == 3)
        #expect(
            summary.parts == [
                .init(instanceName: "Example Yak", count: 2, isFresh: true),
                .init(instanceName: "Other Yak", count: 1, isFresh: false),
                .init(instanceName: "Third Yak", count: 0, isFresh: false),
            ])
        #expect(NeedsYouSummary.make([(instance, counted(1_000_000), true)]).total == 9999)
        #expect(NeedsYouSummary.make([]) == NeedsYouSummary(parts: [], total: 0))
    }

    @Test func entityIDsRoundTripAndRefuseHostileInput() throws {
        let rows = ThreadEntityRows.needsYou(try snapshot("scenario-thread-list"), instance: instance)
        #expect(!rows.isEmpty)
        for row in rows {
            let parsed = try #require(ThreadEntityRows.parse(row.entityID))
            #expect(
                parsed.instance == instance.id && "\(parsed.kind.rawValue)/\(parsed.id)" == row.entityID.dropFirst(37))
        }
        let uuid = instance.id.uuidString
        for hostile in [
            "x", "\(uuid)/thread/../../a", "\(uuid)/folder/d0e515ef", "\(uuid)/thread/d0e515e",
            "\(uuid)/thread/d0e515ef/x",
            "nope/thread/d0e515ef", String(repeating: "a", count: 10_000),
        ] {
            #expect(ThreadEntityRows.parse(hostile) == nil)
        }
    }

    @Test func theLastThreadIsTheLastOpened() async throws {
        let files = InMemoryFileStore()
        let cache = makeCache(files: files)
        #expect(await LastThread.find(cache: cache, instance: instance) == nil)
        await cache.saveThread("d0e515ef", try cachedThread(private: false, title: "Retry failed rows"))
        await cache.saveThread("a1b2c3d4", try cachedThread(private: true, title: "Payroll secret"))
        await cache.markOpened("d0e515ef")
        let open = try #require(await LastThread.find(cache: cache, instance: instance))
        #expect(open.title == .text("Retry failed rows"))
        #expect(open.link.absoluteString == "yak://thread?instance=https%3A%2F%2Fyak.example.com&id=d0e515ef")
        await cache.markOpened("a1b2c3d4")
        let hidden = try #require(await LastThread.find(cache: cache, instance: instance))
        #expect(hidden.title == .privateThread && !String(describing: hidden).contains("Payroll secret"))
        await cache.markOpened("zzzzzzzz")
        let missing = try #require(await LastThread.find(cache: cache, instance: instance))
        #expect(missing.title == .unknown && missing.link.absoluteString.hasSuffix("id=zzzzzzzz"))
        await cache.markOpened("d0e515ef")
        files.isLocked = true
        let locked = try #require(await LastThread.find(cache: cache, instance: instance))
        #expect(locked.title == .unknown && locked.link.absoluteString.hasSuffix("id=d0e515ef"))
    }

    @Test func quickActionsOfferNewThreadAndTheFirstItem() throws {
        let bare = QuickActionItems.make(instance: instance, snapshot: nil)
        #expect(bare.map(\.kind) == [.newThread])
        #expect(bare[0].link.absoluteString == "yak://new?instance=https%3A%2F%2Fyak.example.com")
        #expect(bare[0].subtitle == "Example Yak")
        let items = QuickActionItems.make(instance: instance, snapshot: try snapshot("scenario-thread-list"))
        #expect(items.map(\.kind) == [.newThread, .open(.text("Retry failed rows when the import times out"))])
        let long = WidgetSnapshot(
            instanceName: "x", needsYouCount: 1,
            needsYou: [
                WidgetItem(kind: .thread, id: "abcd1234", title: String(repeating: "a", count: 100), isPrivate: false)
            ],
            running: [], updatedAt: now)
        let cut = QuickActionItems.make(instance: instance, snapshot: long)
        #expect(cut.last?.kind == .open(.text(String(repeating: "a", count: 59) + "…")))
        #expect(QuickActionItems.make(instance: nil, snapshot: long).isEmpty)
    }

    @Test func theFocusFilterAllowsExactlyTheChosenHosts() {
        let home = Instance(
            url: URL(string: "https://Yak.Example.com")!, name: "Home", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 0), canonicalURL: URL(string: "https://alias.example.com")!)
        let other = Instance(
            url: URL(string: "https://other.example.com")!, name: "Other", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 0))
        #expect(
            FocusFilterHosts.allowed(instances: [home, other], chosen: [home.id])
                == ["yak.example.com", "alias.example.com"])
        #expect(FocusFilterHosts.allowed(instances: [home, other], chosen: nil) == nil)
        #expect(FocusFilterHosts.allowed(instances: [home, other], chosen: []) == [])
        #expect(
            FocusFilterHosts.allowed(instances: [home, other], chosen: [UUID(), other.id]) == ["other.example.com"])
    }
}
