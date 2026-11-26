import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private func row(
    _ id: String, title: String = "A thread", status: ThreadRowStatus = .stopped, isStarred: Bool = false,
    canDelete: Bool = true, activity: String? = nil, children: [ThreadRow] = [], reason: NeedsYouReason? = nil
) -> ThreadRow {
    ThreadRow(
        id: id, title: title, repo: "example/web", status: status, needsYouReason: reason, isPrivate: false,
        isShared: false, isUnattended: false, isStarred: isStarred, canDelete: canDelete, lastActivityAt: activity,
        group: .today, children: children)
}

private func list(
    starred: [ThreadRow] = [], needsYou: [ThreadRow] = [], threads: [ThreadRow] = []
) -> ThreadList {
    ThreadList(
        starred: starred, needsYou: needsYou.map { .thread($0) }, threads: threads, needsYouCount: needsYou.count,
        hasAnyThreads: true)
}

private let threadID = "t0pic001"

// MARK: Contract fixtures

@Test func theServerFixturesOfTheThreeCallsDecode() throws {
    let starred = try JSONDecoder().decode(ThreadRow.self, from: Fixtures.data("thread-star"))
    #expect(starred.isStarred && starred.canDelete && starred.status == .stopped && starred.children.isEmpty)
    let closed = try JSONDecoder().decode(ThreadRow.self, from: Fixtures.data("thread-close"))
    #expect(closed.status == .closed && !closed.isStarred)
    let deleted = try JSONDecoder().decode(ThreadDeleted.self, from: Fixtures.data("thread-deleted"))
    #expect(deleted.status == "deleted")
}

@Test func theEndpointsUseTheContractPathsAndBodies() throws {
    let star = Endpoint<ThreadRow>.starThread(threadID, starred: true)
    #expect(star.method == .post && star.path == "threads/\(threadID)/star")
    let body = try JSONEncoder().encode(try #require(star.body.map { AnyEncodable($0) }))
    #expect(String(decoding: body, as: UTF8.self) == #"{"starred":true}"#)
    let close = Endpoint<ThreadRow>.closeThread(threadID)
    #expect(close.method == .post && close.path == "threads/\(threadID)/close" && close.body == nil)
    let delete = Endpoint<ThreadDeleted>.deleteThread(threadID)
    #expect(delete.method == .delete && delete.path == "threads/\(threadID)")
    #expect(close.timeout == .seconds(60) && delete.timeout == .seconds(60))
}

private struct AnyEncodable: Encodable {
    let value: any Encodable
    init(_ value: any Encodable) { self.value = value }
    func encode(to encoder: any Encoder) throws { try value.encode(to: encoder) }
}

// MARK: List edits

@Test func aStarMovesTheRowToTheTopOfStarredAndBack() {
    let original = list(
        starred: [row("old00001", isStarred: true)],
        threads: [
            row("new00001", activity: "2026-11-15T09:00:00Z"), row("mid00001", activity: "2026-11-14T09:00:00Z"),
            row("old0000z", activity: "2026-11-10T09:00:00Z"),
        ])
    let starred = original.applying(.setStar(id: "mid00001", isStarred: true))
    #expect(starred.starred.map(\.id) == ["mid00001", "old00001"])
    #expect(starred.starred[0].isStarred)
    #expect(starred.threads.map(\.id) == ["new00001", "old0000z"])
    let back = starred.applying(.setStar(id: "mid00001", isStarred: false))
    #expect(back.starred.map(\.id) == ["old00001"])
    #expect(back.threads.map(\.id) == ["new00001", "mid00001", "old0000z"])
    #expect(!back.threads[1].isStarred)
}

@Test func aStarOnAThreadThatNeedsThePersonKeepsItUnderNeedsYou() {
    let original = list(needsYou: [row("ask00001", reason: .question)])
    let starred = original.applying(.setStar(id: "ask00001", isStarred: true))
    #expect(starred.starred.isEmpty && starred.needsYou.count == 1)
    #expect(starred.row(ofThread: "ask00001")?.isStarred == true)
}

@Test func theServersRowReplacesTheRowAndKeepsItsSubThreadsWhenTheAnswerNamesNone() {
    let child = row("chi1d001", title: "Child")
    let original = list(threads: [row("par3nt01", children: [child])])
    let answer = row("par3nt01", status: .closed)
    let replaced = original.applying(.replace(answer))
    #expect(replaced.threads[0].status == .closed)
    #expect(replaced.threads[0].children.map(\.id) == ["chi1d001"])
}

@Test func aRemoveTakesTheThreadOutOfEveryGroupAndFixesTheCounts() {
    let child = row("chi1d001")
    let original = list(
        starred: [row("star0001", isStarred: true)], needsYou: [row("ask00001", reason: .question)],
        threads: [row("par3nt01", children: [child]), row("last0001")])
    let withoutChild = original.applying(.remove(id: "chi1d001"))
    #expect(withoutChild.threads[0].children.isEmpty && withoutChild.threads.count == 2)
    let withoutNeedy = original.applying(.remove(id: "ask00001"))
    #expect(withoutNeedy.needsYou.isEmpty && withoutNeedy.needsYouCount == 0)
    let withoutRoot = original.applying(.remove(id: "par3nt01"))
    #expect(withoutRoot.row(ofThread: "chi1d001") == nil && withoutRoot.threads.map(\.id) == ["last0001"])
    #expect(original.applying(.remove(id: "nothing1")) == original)
}

// MARK: The menu

private func actionIDs(_ menu: ThreadActionMenu) -> [[String]] { menu.groups.map { $0.map(\.id) } }

private func drawerRow(
    isStarred: Bool = false, status: ThreadRowStatus = .stopped, canDelete: Bool = true, sourceURL: String? = nil,
    isChild: Bool = false
) throws -> DrawerRow {
    let source = sourceURL.map { ThreadSource(key: SourceKey(rawValue: "slack"), label: "Slack", url: $0) }
    let thread = ThreadRow(
        id: "t0pic001", title: "A thread", repo: "example/web", status: status, isPrivate: false, isShared: false,
        isUnattended: false, isStarred: isStarred, canDelete: canDelete, group: .today,
        children: isChild ? [] : [], source: source)
    let parent =
        isChild
        ? ThreadRow(
            id: "par3nt01", title: "P", repo: "example/web", status: .running, isPrivate: false, isShared: false,
            isUnattended: false, isStarred: false, canDelete: true, group: .today, children: [thread]) : thread
    let content = DrawerSections.make(
        list: list(threads: [parent]), filter: .mine, search: nil, expanded: ["thread:par3nt01": true],
        unsentNewThreads: [])
    guard case .sections(let sections) = content else { throw ThreadLifecycleTestError.noRows }
    return try #require(sections.flatMap(\.rows).first { $0.id == "thread:t0pic001" })
}

private enum ThreadLifecycleTestError: Error { case noRows }

@Test func aRootRowOffersStarCloseAndDeleteInOneGroup() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(), isOffline: false))
    #expect(actionIDs(menu) == [["open", "new-window", "star", "copy-link", "close", "delete"]])
    #expect(menu.item(.delete)?.isDestructive == true && menu.item(.close)?.isDestructive == false)
    #expect(menu.items.allSatisfy { $0.isEnabled })
}

@Test func aStarredRowOffersRemoveStar() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(isStarred: true), isOffline: false))
    #expect(menu.item(.unstar) != nil && menu.item(.star) == nil)
}

@Test func aClosedRowOffersNoCloseButStillDelete() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(status: .closed), isOffline: false))
    #expect(menu.item(.close) == nil && menu.item(.delete) != nil)
}

@Test func aRowTheyMayNotDriveOffersOnlyWhatReadingAllows() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(canDelete: false), isOffline: false))
    #expect(actionIDs(menu) == [["open", "new-window", "star", "copy-link"]])
}

@Test func aSubThreadRowOffersNoStarCloseOrDelete() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(isChild: true), isOffline: false))
    #expect(actionIDs(menu) == [["open", "new-window", "copy-link"]])
}

@Test func theSourceItemNamesTheSourceAndFollowsTheLinkRule() throws {
    let menu = ThreadActionMenu.make(
        .row(try drawerRow(sourceURL: "https://example.com/archives/C1/p1"), isOffline: false))
    #expect(menu.item(.openSource(name: "Slack", url: URL(string: "https://example.com/archives/C1/p1")!)) != nil)
    let unsafe = ThreadActionMenu.make(.row(try drawerRow(sourceURL: "javascript:alert(1)"), isOffline: false))
    #expect(!unsafe.items.contains { $0.id == "open-source" })
    let plain = ThreadActionMenu.make(.row(try drawerRow(sourceURL: "http://example.com/x"), isOffline: false))
    #expect(!plain.items.contains { $0.id == "open-source" })
}

@Test func offlineDisablesWhatNeedsTheServerAndNothingElse() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(), isOffline: true))
    let disabled = menu.items.filter { !$0.isEnabled }.map(\.id)
    #expect(disabled == ["star", "close", "delete"])
    #expect(menu.items.filter { !$0.isEnabled }.allSatisfy { $0.disabledReason == .offline })
    #expect(menu.item(.copyLink)?.isEnabled == true && menu.item(.open)?.isEnabled == true)
}

@Test func aRunningRequestDisablesTheThreadsOtherActions() throws {
    let menu = ThreadActionMenu.make(.row(try drawerRow(), isOffline: true, isBusy: true))
    #expect(menu.items.filter { !$0.isEnabled }.allSatisfy { $0.disabledReason == .busy })
    #expect(menu.items.filter { !$0.isEnabled }.map(\.id) == ["star", "close", "delete"])
}

private func headerSubject(
    canDrive: Bool = true, isOwner: Bool = true, status: String = "open", parent: String? = nil,
    isStarred: Bool = false, flags: ThreadActionFlags = ThreadActionFlags(canCancel: true, canRequestReview: true),
    isOffline: Bool = false
) throws -> ThreadActionSubject {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    var thread = try #require(object["thread"] as? [String: Any])
    thread["status"] = status
    thread["parentThreadId"] = parent as Any? ?? NSNull()
    thread["isOwner"] = isOwner
    thread["isStarred"] = isStarred
    object["thread"] = thread
    object["canDrive"] = canDrive
    object["actions"] = try JSONSerialization.jsonObject(with: JSONEncoder().encode(flags))
    let show = try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
    return ThreadActionSubject.header(show: show, isOffline: isOffline)
}

@Test func theHeaderMenuPutsStarFirstAndCancelCloseAndDeleteLast() throws {
    let menu = ThreadActionMenu.make(try headerSubject())
    #expect(
        actionIDs(menu) == [
            ["star", "share", "add-sub-thread"], ["request-review"], ["panes", "open-web", "copy-link"],
            ["cancel", "close", "delete"],
        ])
    #expect(menu.items.filter(\.isDestructive).map(\.id) == ["cancel", "delete"])
}

@Test func aShareRecipientsHeaderMenuHasNeitherCloseNorDelete() throws {
    let menu = ThreadActionMenu.make(try headerSubject(canDrive: false, isOwner: false, flags: .none))
    #expect(actionIDs(menu) == [["star"], ["panes", "open-web", "copy-link"]])
}

@Test func aSubThreadsHeaderMenuHasNoStarAndNoDeleteButCanClose() throws {
    let menu = ThreadActionMenu.make(try headerSubject(parent: "par3nt01"))
    #expect(menu.item(.star) == nil && menu.item(.unstar) == nil && menu.item(.delete) == nil)
    #expect(menu.item(.close) != nil)
}

@Test func aClosedThreadsHeaderMenuOffersNoClose() throws {
    let menu = ThreadActionMenu.make(try headerSubject(status: "closed", flags: .none))
    #expect(menu.item(.close) == nil && menu.item(.delete) != nil)
}

@Test func theHeaderAndTheRowAgreeOnWhoSeesWhat() throws {
    for (canDrive, canDelete) in [(true, true), (false, false)] {
        let header = ThreadActionMenu.make(try headerSubject(canDrive: canDrive, flags: .none))
        let rowMenu = ThreadActionMenu.make(.row(try drawerRow(canDelete: canDelete), isOffline: false))
        for kind in [ThreadActionKind.star, .close, .delete, .copyLink] {
            #expect((header.item(kind) != nil) == (rowMenu.item(kind) != nil))
        }
    }
}

@Test func theHeadersStarFollowsAnOptimisticValue() throws {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-thread-show")) as? [String: Any])
    object["canDrive"] = true
    let show = try JSONDecoder().decode(ThreadShow.self, from: JSONSerialization.data(withJSONObject: object))
    let subject = ThreadActionSubject.header(show: show, isStarred: true, isOffline: false)
    #expect(ThreadActionMenu.make(subject).item(.unstar) != nil)
}

@Test func beforeThePageLoadsTheHeaderMenuOffersOnlyWhatNeedsNoPage() {
    let menu = ThreadActionMenu.make(ThreadActionSubject.header(show: nil, isOffline: false))
    #expect(actionIDs(menu) == [["panes", "open-web", "copy-link"]])
}

// MARK: The actions

private struct Rig {
    let lifecycle: ThreadLifecycle
    let feed: ThreadListFeed
    let transport: ScriptedTransport
    let cache: Cache
    let outbox: Outbox
    let copies: OfflineCopyStore
    let files: InMemoryFileStore
    let instance: Instance
}

private func makeRig(_ cachedMine: ThreadList) async throws -> Rig {
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let instances = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { clock.now() })
    let instance = try await instances.add(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "t")
    let cache = Cache(instance: instance.id, files: files, clock: clock)
    for tab in ThreadListFilter.allCases { await cache.saveThreadList(cachedMine, filter: tab) }
    let transport = ScriptedTransport()
    let client = APIClient(
        baseURL: instance.url, token: "t", transport: transport, appVersion: "1.0", systemDescription: "Test",
        sleep: { _ in })
    let feed = ThreadListFeed(instance: instance, client: client, cache: cache, instances: instances, clock: clock)
    let outbox = Outbox(
        instance: instance.id, client: client, files: files, clock: clock, makeID: IDSequence().next)
    let copies = OfflineCopyStore(instance: instance.id, files: files, clock: clock)
    let lifecycle = ThreadLifecycle(
        client: client, cache: cache, feed: feed, offlineCopies: copies, outbox: outbox)
    return Rig(
        lifecycle: lifecycle, feed: feed, transport: transport, cache: cache, outbox: outbox, copies: copies,
        files: files, instance: instance)
}

private func rowJSON(_ id: String, status: String = "stopped", isStarred: Bool = false) -> String {
    """
    {"id":"\(id)","title":"A thread","repo":"example/web","status":"\(status)","isPrivate":false,"isShared":false,\
    "isUnattended":false,"isStarred":\(isStarred),"canDelete":true,"group":"today","children":[],"type":"thread"}
    """
}

private func problem(_ status: Int, _ code: String, _ message: String = "m") -> FakeTransport.Result {
    .json(status, #"{"error":"\#(code)","message":"\#(message)"}"#)
}

private let starRoute = "POST threads/\(threadID)/star"
private let closeRoute = "POST threads/\(threadID)/close"
private let deleteRoute = "DELETE threads/\(threadID)"

@Test func aStarShowsAtOnceAndTheServersRowSettlesIt() async throws {
    let rig = try await makeRig(list(threads: [row(threadID), row("other001")]))
    await rig.transport.hold(starRoute)
    await rig.transport.on(starRoute, .json(200, rowJSON(threadID, isStarred: true)))
    async let outcome = rig.lifecycle.setStar(threadID, to: true)
    await rig.transport.waitForRequests(1, on: starRoute)
    let during = try #require(await rig.cache.threadList(.mine))
    #expect(during.starred.map(\.id) == [threadID])
    await rig.transport.release(starRoute)
    #expect(await outcome == .done)
    let after = try #require(await rig.cache.threadList(.closed))
    #expect(after.starred.map(\.id) == [threadID] && after.threads.map(\.id) == ["other001"])
}

@Test func aRefusedStarGoesBack() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.on(starRoute, problem(500, "server_error"))
    #expect(await rig.lifecycle.setStar(threadID, to: true) == .failed(.failed))
    let after = try #require(await rig.cache.threadList(.mine))
    #expect(after.starred.isEmpty && after.threads.map(\.id) == [threadID])
}

@Test func twoTapsOnTheSameStarSendOneRequest() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.hold(starRoute)
    await rig.transport.on(starRoute, .json(200, rowJSON(threadID, isStarred: true)))
    async let first = rig.lifecycle.setStar(threadID, to: true)
    async let second = rig.lifecycle.setStar(threadID, to: true)
    await rig.transport.waitForRequests(1, on: starRoute)
    await rig.transport.release(starRoute)
    #expect(await (first, second) == (.done, .done))
    #expect(await rig.transport.requests(on: starRoute).count == 1)
}

@Test func closeWaitsForTheServerThenTheRowTakesItsPlace() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.on(closeRoute, .json(200, rowJSON(threadID, status: "closed")))
    await rig.transport.on("GET threads", .json(200, "{}"))
    let changes = StreamRecorder(await rig.lifecycle.changes())
    #expect(await rig.lifecycle.close(threadID) == .done)
    _ = try await changes.next { $0 == .closed(id: threadID) }
    let after = try #require(await rig.cache.threadList(.mine))
    #expect(after.threads.first?.status == .closed)
}

@Test func aDeleteRemovesEverythingTheDeviceHoldsForTheThread() async throws {
    let rig = try await makeRig(
        list(starred: [row("star0001", isStarred: true)], threads: [row(threadID), row("other001")]))
    await rig.transport.on(deleteRoute, .json(200, #"{"id":"\#(threadID)","status":"deleted"}"#))
    await rig.transport.on("POST threads/\(threadID)/messages", .failure(.notConnectedToInternet))
    await rig.transport.on("POST threads/other001/messages", .failure(.notConnectedToInternet))
    _ = try await rig.outbox.enqueue(OutboxDraft(target: .thread(threadID), text: "unsent"))
    _ = try await rig.outbox.enqueue(OutboxDraft(target: .thread("other001"), text: "kept"))
    await rig.transport.waitForRequests(1, on: "POST threads/\(threadID)/messages")
    await rig.cache.saveComposerDraft(ComposerDraft(text: "draft"), for: .thread(threadID))
    #expect(await rig.copies.save(Data("page".utf8), thread: threadID) == .saved)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await rig.cache.saveThread(threadID, CachedThread(show: show, events: [], lastSequence: 0, firstLoadedAfter: 0))
    let changes = StreamRecorder(await rig.lifecycle.changes())

    #expect(await rig.lifecycle.delete(threadID) == .done)
    _ = try await changes.next { $0 == .deleted(id: threadID, subThreadIDs: []) }

    for tab in ThreadListFilter.allCases {
        let saved = try #require(await rig.cache.threadList(tab))
        #expect(saved.row(ofThread: threadID) == nil && saved.row(ofThread: "other001") != nil)
    }
    #expect(await rig.cache.thread(threadID) == nil)
    #expect(await rig.cache.composerDraft(.thread(threadID)) == nil)
    #expect(await rig.copies.copy(thread: threadID) == nil)
    #expect(await rig.outbox.items().map(\.target) == [.thread("other001")])
}

@Test func aClosedThreadKeepsItsOfflineCopy() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    #expect(await rig.copies.save(Data("page".utf8), thread: threadID) == .saved)
    await rig.transport.on(closeRoute, .json(200, rowJSON(threadID, status: "closed")))
    await rig.transport.on("GET threads", .json(200, "{}"))
    #expect(await rig.lifecycle.close(threadID) == .done)
    #expect(await rig.copies.copy(thread: threadID) != nil)
}

@Test func theDeleteStateShowsWhileTheRequestRuns() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.hold(deleteRoute)
    await rig.transport.on(deleteRoute, .json(200, #"{"id":"\#(threadID)","status":"deleted"}"#))
    let states = StreamRecorder(await rig.lifecycle.states())
    async let outcome = rig.lifecycle.delete(threadID)
    _ = try await states.next { $0.isDeleting(threadID) && $0.isBusy(threadID) }
    await rig.transport.release(deleteRoute)
    #expect(await outcome == .done)
    _ = try await states.next { !$0.isBusy(threadID) }
}

@Test(arguments: [
    (403, "forbidden", ThreadLifecycleFailure.forbidden),
    (500, "server_error", .failed),
    (503, "unavailable", .failed),
    (409, "something_else", .failed),
])
func aFailedDeleteChangesNothingAndNamesItsFailure(status: Int, code: String, failure: ThreadLifecycleFailure)
    async throws
{
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.on(deleteRoute, problem(status, code))
    #expect(await rig.lifecycle.delete(threadID) == .failed(failure))
    #expect(try #require(await rig.cache.threadList(.mine)).row(ofThread: threadID) != nil)
}

@Test func theSandboxRefusalCarriesTheServersSentenceAndDeletesNothing() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    let sentence = "The sandbox could not be destroyed yet. Try again in a moment."
    await rig.transport.on(deleteRoute, problem(409, "sandbox_unavailable", sentence))
    #expect(await rig.lifecycle.delete(threadID) == .failed(.sandboxUnavailable(message: sentence)))
    #expect(try #require(await rig.cache.threadList(.mine)).row(ofThread: threadID) != nil)
    await rig.transport.on(deleteRoute, .json(200, #"{"id":"\#(threadID)","status":"deleted"}"#))
    #expect(await rig.lifecycle.delete(threadID) == .done)
}

@Test func aThreadThatIsGoneIsRemovedLocallyWhateverTheAction() async throws {
    for action in 0..<2 {
        let rig = try await makeRig(list(threads: [row(threadID)]))
        await rig.transport.on(starRoute, problem(404, "not_found"))
        await rig.transport.on(closeRoute, problem(404, "not_found"))
        let changes = StreamRecorder(await rig.lifecycle.changes())
        let outcome =
            action == 0 ? await rig.lifecycle.setStar(threadID, to: true) : await rig.lifecycle.close(threadID)
        #expect(outcome == .failed(.gone))
        _ = try await changes.next { $0 == .gone(id: threadID, subThreadIDs: []) }
        #expect(try #require(await rig.cache.threadList(.mine)).row(ofThread: threadID) == nil)
    }
}

@Test func aDeleteTheServerAnswers404ForIsDoneBecauseTheThreadIsGone() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    let changes = StreamRecorder(await rig.lifecycle.changes())
    await rig.transport.on(deleteRoute, .failure(.timedOut))
    #expect(await rig.lifecycle.delete(threadID) == .failed(.failed))
    #expect(try #require(await rig.cache.threadList(.mine)).row(ofThread: threadID) != nil)
    await rig.transport.on(deleteRoute, problem(404, "not_found"))
    #expect(await rig.lifecycle.delete(threadID) == .done)
    _ = try await changes.next { $0 == .deleted(id: threadID, subThreadIDs: []) }
    #expect(try #require(await rig.cache.threadList(.mine)).row(ofThread: threadID) == nil)
}

@Test func aDeleteRemovesWhatTheSubThreadsHoldToo() async throws {
    let child = row("chi1d001")
    let rig = try await makeRig(list(threads: [row(threadID, children: [child])]))
    await rig.transport.on(deleteRoute, .json(200, #"{"id":"\#(threadID)","status":"deleted"}"#))
    await rig.transport.on("POST threads/chi1d001/messages", .failure(.notConnectedToInternet))
    _ = try await rig.outbox.enqueue(OutboxDraft(target: .thread("chi1d001"), text: "unsent"))
    await rig.transport.waitForRequests(1, on: "POST threads/chi1d001/messages")
    await rig.cache.saveComposerDraft(ComposerDraft(text: "draft"), for: .thread("chi1d001"))
    #expect(await rig.copies.save(Data("page".utf8), thread: "chi1d001") == .saved)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    await rig.cache.saveThread("chi1d001", CachedThread(show: show, events: [], lastSequence: 0, firstLoadedAfter: 0))
    let changes = StreamRecorder(await rig.lifecycle.changes())

    #expect(await rig.lifecycle.delete(threadID) == .done)
    _ = try await changes.next { $0 == .deleted(id: threadID, subThreadIDs: ["chi1d001"]) }

    #expect(await rig.cache.thread("chi1d001") == nil)
    #expect(await rig.cache.composerDraft(.thread("chi1d001")) == nil)
    #expect(await rig.copies.copy(thread: "chi1d001") == nil)
    #expect(await rig.outbox.items().isEmpty)
}

@Test func aRefusedStarGoesBackToWhatTheListHeldNotToTheOpposite() async throws {
    let rig = try await makeRig(list(starred: [row(threadID, isStarred: true)]))
    await rig.transport.on(starRoute, problem(500, "server_error"))
    #expect(await rig.lifecycle.setStar(threadID, to: true) == .failed(.failed))
    let after = try #require(await rig.cache.threadList(.mine))
    #expect(after.starred.map(\.id) == [threadID] && after.starred[0].isStarred)
}

@Test func theFeedNamesTheWholeSubtreeOfAThread() async throws {
    let grandchild = row("gr4nd001")
    let rig = try await makeRig(
        list(threads: [row(threadID, children: [row("chi1d001", children: [grandchild])])]))
    #expect(await rig.feed.subtreeIDs(of: threadID) == [threadID, "chi1d001", "gr4nd001"])
    #expect(await rig.feed.subtreeIDs(of: "other001") == ["other001"])
    #expect(await rig.feed.isStarred(threadID) == false)
}

@Test func offlineAndTimeoutAreToldApart() async throws {
    let rig = try await makeRig(list(threads: [row(threadID)]))
    await rig.transport.on(closeRoute, .failure(.notConnectedToInternet))
    #expect(await rig.lifecycle.close(threadID) == .failed(.offline))
    await rig.transport.on(closeRoute, .failure(.timedOut))
    #expect(await rig.lifecycle.close(threadID) == .failed(.failed))
    await rig.transport.on(closeRoute, .json(401, #"{"error":"device_revoked","message":"m"}"#))
    #expect(await rig.lifecycle.close(threadID) == .failed(.unauthorized))
}

@Test func aDrawerRowCarriesTheStarTheRightToDriveAndTheSourceLink() throws {
    let source = ThreadSource(key: SourceKey(rawValue: "slack"), label: "Slack", url: "https://example.com/p/1")
    let started = ThreadRow(
        id: "t0pic001", title: "A", repo: "example/web", status: .closed, isPrivate: false, isShared: false,
        isUnattended: false, isStarred: true, canDelete: true, group: .today, children: [], source: source)
    let content = DrawerSections.make(
        list: list(starred: [started]), filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
    guard case .sections(let sections) = content, let drawerRow = sections.first?.rows.first else {
        Issue.record("no rows")
        return
    }
    #expect(sections.first?.kind == .starred)
    #expect(drawerRow.isStarred && drawerRow.canDelete && drawerRow.indicator == .closed)
    #expect(drawerRow.sourceLink == URL(string: "https://example.com/p/1"))
    #expect(drawerRow.repositoryDetail == nil && drawerRow.subtitle == .status(.closed))
}

@Test func theStarredGroupSitsAboveNeedsYouAsOnTheWeb() {
    let content = DrawerSections.make(
        list: list(
            starred: [row("star0001", isStarred: true)], needsYou: [row("ask00001", reason: .question)],
            threads: [row("last0001")]),
        filter: .mine, search: nil, expanded: [:], unsentNewThreads: [])
    guard case .sections(let sections) = content else {
        Issue.record("no sections")
        return
    }
    #expect(sections.map(\.kind) == [.starred, .needsYou, .today])
}

@Test func aRowCountsEverySubThreadBelowIt() {
    let grandchild = row("gr4nd001")
    let child = row("chi1d001", children: [grandchild])
    #expect(row("par3nt01", children: [child, row("chi1d002")]).subThreadCount == 3)
    #expect(row("lone0001").subThreadCount == 0)
}
