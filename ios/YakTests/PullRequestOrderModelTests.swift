import Foundation
import Testing
import YakKit

@testable import Yak

/// A server for the merge order: the list the card reads and the answer to the PUT.
private final class OrderServer: @unchecked Sendable {
    private let lock = NSLock()
    private var order = [77, 78]
    private var merged: Set<Int> = []
    private var canDrive = true
    private var losesTheNextAnswer = false
    private var failsReads = false
    private(set) var puts: [[Int]] = []
    var putAnswer: (status: Int, body: Data) = (200, Data(#"{"status":"updated"}"#.utf8))

    func setFailsReads(_ value: Bool) { lock.withLock { failsReads = value } }
    func loseTheNextAnswer() { lock.withLock { losesTheNextAnswer = true } }
    func setCanDrive(_ value: Bool) { lock.withLock { canDrive = value } }
    func merge(_ id: Int) { lock.withLock { _ = merged.insert(id) } }
    func putCount() -> Int { lock.withLock { puts.count } }
    func lastPut() -> [Int]? { lock.withLock { puts.last } }
    func serverOrder() -> [Int] { lock.withLock { order } }

    func answer(route: String, body: Data) -> (status: Int, body: Data)? {
        if route == "GET threads/d0e515ef/pull-requests" {
            if lock.withLock({ failsReads }) { return (500, Data(#"{"error":"server_error","message":""}"#.utf8)) }
            var panel = ScenarioFile.object("scenario-pull-requests")
            let rows = panel["pullRequests"] as? [[String: Any]] ?? []
            let (current, closed, drive) = lock.withLock { (order, merged, canDrive) }
            panel["pullRequests"] = current.compactMap { id in
                rows.first { $0["rowId"] as? Int == id }.map { row in
                    var row = row
                    if closed.contains(id) { row["state"] = "merged" }
                    return row
                }
            }
            panel["canDrive"] = drive
            return (200, (try? JSONSerialization.data(withJSONObject: panel)) ?? Data())
        }
        if route == "PUT threads/d0e515ef/pull-requests/order" {
            let sent = ((try? JSONSerialization.jsonObject(with: body)) as? [String: Any])?["order"] as? [Int] ?? []
            lock.withLock { puts.append(sent) }
            if putAnswer.status == 200 { lock.withLock { order = sent } }
            if lock.withLock({ () -> Bool in
                defer { losesTheNextAnswer = false }
                return losesTheNextAnswer
            }) {
                lock.withLock { order = sent }
                return (500, errorBody("server_error", ""))
            }
            return putAnswer
        }
        return nil
    }
}

@MainActor private func openedCard(
    _ server: OrderServer, slowAfter: Duration = .seconds(8)
) async throws -> (PullRequestCardModel, TasksFixture) {
    let fixture = try await TasksFixture(answer: { route, _, body in server.answer(route: route, body: body) })
    let model = PullRequestCardModel(threadID: "d0e515ef", session: fixture.session, slowAfter: slowAfter)
    await model.start()
    #expect(await fixture.eventually { model.rows.count == 2 })
    return (model, fixture)
}

@MainActor private func finish(_ model: PullRequestCardModel, _ fixture: TasksFixture) async {
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aDropSavesTheNewOrderAndTheServerAnswerConfirmsIt() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    #expect(model.isEditing)
    #expect(model.openRows.map(\.id) == [77, 78])
    await model.moveRows(from: IndexSet(integer: 1), to: 0)
    #expect(server.lastPut() == [78, 77])
    #expect(model.orderState == .idle)
    #expect(model.orderNotice == nil)
    #expect(model.rows.map(\.id) == [78, 77])
    #expect(model.orderSuccessCount == 1)
    await finish(model, fixture)
}

@MainActor @Test func theAccessibilityActionMovesOneRowAndAnnouncesItsPlace() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    await model.moveRow(77, by: 1)
    #expect(server.lastPut() == [78, 77])
    #expect(model.orderAnnouncement == "Moved to place 2 of 2")
    await model.moveRow(77, by: 1)
    #expect(server.putCount() == 1)
    await finish(model, fixture)
}

@MainActor @Test func aFailedSavePutsTheRowsBackAndRetryResendsTheSameOrder() async throws {
    let server = OrderServer()
    server.putAnswer = (500, errorBody("server_error", ""))
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    await model.moveRows(from: IndexSet(integer: 0), to: 2)
    #expect(model.openRows.map(\.id) == [77, 78])
    guard case .failed = model.orderState else {
        Issue.record("expected a failure line, got \(model.orderState)")
        return
    }
    #expect(model.errorCount == 1)
    server.putAnswer = (200, Data(#"{"status":"updated"}"#.utf8))
    await model.retryOrder()
    #expect(model.orderState == .idle)
    #expect(model.openRows.map(\.id) == [78, 77])
    #expect(server.serverOrder() == [78, 77])
    await finish(model, fixture)
}

@MainActor @Test func aConflictReloadsTheListAndSaysSo() async throws {
    let server = OrderServer()
    server.putAnswer = (
        422,
        errorBody(
            "validation_failed", "The request is not valid.",
            details: ["errors": ["order": ["The order must list each pull request of this thread exactly once."]]])
    )
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    server.merge(78)
    await model.moveRow(77, by: 1)
    #expect(model.orderNotice == "The pull requests changed, so the list was reloaded.")
    #expect(model.rows.first { $0.id == 78 }?.state == .merged)
    #expect(model.orderState == .idle)
    // One open row is left, so there is nothing to order.
    #expect(!model.isEditing)
    await finish(model, fixture)
}

@MainActor @Test func aListThatChangedDuringTheSaveIsSaidAfterTheAnswer() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    server.merge(78)
    await model.moveRow(77, by: 1)
    #expect(model.orderNotice == "The pull requests changed, so the list was reloaded.")
    await finish(model, fixture)
}

@MainActor @Test func editIsHiddenOfflineDisabledAndForReaders() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    #expect(model.editAvailability(isOnline: true) == .available)
    #expect(model.editAvailability(isOnline: false) == .offline)
    model.beginEditing(isOnline: false)
    #expect(!model.isEditing)
    await finish(model, fixture)

    let readerServer = OrderServer()
    readerServer.setCanDrive(false)
    let (reader, readerFixture) = try await openedCard(readerServer)
    #expect(reader.editAvailability(isOnline: true) == .hidden)
    reader.beginEditing(isOnline: true)
    #expect(!reader.isEditing)
    await finish(reader, readerFixture)
}

@MainActor @Test func onePullRequestShowsNoEdit() async throws {
    let server = OrderServer()
    server.merge(78)
    let (model, fixture) = try await openedCard(server)
    #expect(model.editAvailability(isOnline: true) == .hidden)
    await finish(model, fixture)
}

@MainActor @Test func aSlowSaySlowAndIsNotSentTwice() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server, slowAfter: .milliseconds(30))
    let gate = AsyncGate()
    fixture.transport.beforeAnswer { route in
        if route.hasPrefix("PUT") { await gate.wait() }
    }
    model.beginEditing(isOnline: true)
    let saving = Task { await model.moveRow(77, by: 1) }
    #expect(await fixture.eventually { model.orderState == .slow })
    #expect(model.isSavingOrder)
    await model.moveRow(78, by: 1)
    await model.moveRow(77, by: -1)
    model.endEditing()
    #expect(model.isEditing)
    await gate.open()
    await saving.value
    #expect(server.putCount() == 1)
    #expect(model.orderState == .idle)
    await finish(model, fixture)
}

@MainActor @Test func aLivePanelWaitsForTheEndOfTheMove() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    server.merge(78)
    await model.reload()
    try await Task.sleep(for: .milliseconds(100))
    // The rows under the finger stay as they were while editing.
    #expect(model.rows.first { $0.id == 78 }?.state == .open)
    #expect(model.openRows.map(\.id) == [77, 78])
    model.endEditing()
    #expect(await fixture.eventually { model.rows.first { $0.id == 78 }?.state == .merged })
    await finish(model, fixture)
}

/// A gate a request waits at until the test opens it.
private actor AsyncGate {
    private var isOpen = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    func wait() async {
        if isOpen { return }
        await withCheckedContinuation { waiters.append($0) }
    }

    func open() {
        isOpen = true
        for waiter in waiters { waiter.resume() }
        waiters = []
    }
}

@MainActor @Test func aValidationErrorOnAnotherFieldIsAFailureNotAChange() async throws {
    let server = OrderServer()
    server.putAnswer = (
        422, errorBody("validation_failed", "The request is not valid.", details: ["errors": ["other": ["Nope."]]])
    )
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    await model.moveRow(77, by: 1)
    #expect(model.orderNotice == nil)
    #expect(model.orderState == .failed("The request is not valid."))
    #expect(model.openRows.map(\.id) == [77, 78])
    await finish(model, fixture)
}

@MainActor @Test func aSavedOrderStaysWhenTheReloadFailsAndAnOlderPanelDoesNotMoveItBack() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    // A panel read before the save is held.
    await model.reload()
    try await Task.sleep(for: .milliseconds(100))
    server.setFailsReads(true)
    await model.moveRow(77, by: 1)
    #expect(server.serverOrder() == [78, 77])
    #expect(model.orderState == .idle)
    #expect(model.openRows.map(\.id) == [78, 77])
    model.endEditing()
    #expect(model.rows.map(\.id) == [78, 77])
    await finish(model, fixture)
}

/// The PUT carries the full order, so after a lost answer the same order sent again leaves the server as it is.
@MainActor @Test func aRetryAfterALostAnswerLeavesTheSameOrder() async throws {
    let server = OrderServer()
    server.loseTheNextAnswer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    await model.moveRow(77, by: 1)
    guard case .failed = model.orderState else {
        Issue.record("expected a failure line")
        return
    }
    #expect(server.serverOrder() == [78, 77])
    await model.retryOrder()
    #expect(model.orderState == .idle)
    #expect(server.putCount() == 2)
    #expect(server.serverOrder() == [78, 77])
    #expect(model.openRows.map(\.id) == [78, 77])
    await finish(model, fixture)
}

@MainActor @Test func aMoveThatLandsAfterDoneIsIgnored() async throws {
    let server = OrderServer()
    let (model, fixture) = try await openedCard(server)
    model.beginEditing(isOnline: true)
    model.endEditing()
    await model.moveRows(from: IndexSet(integer: 1), to: 0)
    #expect(server.putCount() == 0)
    await finish(model, fixture)
}
