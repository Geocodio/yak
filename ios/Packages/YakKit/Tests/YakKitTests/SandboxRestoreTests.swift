import Foundation
import Testing

@testable import YakKit

/// A `perform` that waits until the test opens it and counts its calls.
private actor RestoreProbe {
    private(set) var calls = 0
    private var isOpen = false
    private var waiters: [CheckedContinuation<Void, Never>] = []
    private let outcome: ThreadActionOutcome

    init(outcome: ThreadActionOutcome = .done, startsOpen: Bool = false) {
        self.outcome = outcome
        isOpen = startsOpen
    }

    func perform() async -> ThreadActionOutcome {
        calls += 1
        if !isOpen { await withCheckedContinuation { waiters.append($0) } }
        return outcome
    }

    func open() {
        isOpen = true
        let ready = waiters
        waiters = []
        for waiter in ready { waiter.resume() }
    }

    func waitForCalls(_ count: Int) async {
        let deadline = ContinuousClock.now + .seconds(10)
        while calls < count, ContinuousClock.now < deadline { await Task.yield() }
    }
}

private func restore(_ probe: RestoreProbe) -> SandboxRestore {
    SandboxRestore(perform: { await probe.perform() })
}

@Suite struct SandboxRestoreTests {
    @Test func concurrentTapsSendOneRequest() async {
        let probe = RestoreProbe()
        let restore = restore(probe)
        let first = Task { await restore.run() }
        await probe.waitForCalls(1)
        let second = Task { await restore.run() }
        let third = Task { await restore.run() }
        for _ in 0..<200 { await Task.yield() }
        #expect(await restore.isRunning)
        await probe.open()
        #expect(await first.value == .started)
        #expect(await second.value == .started)
        #expect(await third.value == .started)
        #expect(await probe.calls == 1)
        #expect(await !restore.isRunning)
    }

    @Test func aTapWhileRestoringIsCoalesced() async {
        let probe = RestoreProbe()
        let restore = restore(probe)
        let filesPane = Task { await restore.run() }
        await probe.waitForCalls(1)
        let terminalPane = Task { await restore.run() }
        for _ in 0..<200 { await Task.yield() }
        await probe.open()
        let outcomes = [await filesPane.value, await terminalPane.value]
        #expect(outcomes == [.started, .started])
        #expect(await probe.calls == 1)
    }

    @Test func aLaterTapAsksAgain() async {
        let probe = RestoreProbe(startsOpen: true)
        let restore = restore(probe)
        #expect(await restore.run() == .started)
        #expect(await restore.run() == .started)
        #expect(await probe.calls == 2)
    }

    @Test func nothingToRestoreCountsAsStarted() async {
        let probe = RestoreProbe(
            outcome: .refused(code: "nothing_to_restore", message: "Nothing to restore.", fieldErrors: [:]),
            startsOpen: true)
        #expect(await restore(probe).run() == .started)
    }

    @Test func refusalsKeepTheServersMessage() async {
        let refused = RestoreProbe(
            outcome: .refused(code: "forbidden", message: "Only the person driving can restore.", fieldErrors: [:]),
            startsOpen: true)
        #expect(await restore(refused).run() == .refused(message: "Only the person driving can restore."))
        let failed = RestoreProbe(outcome: .failed(.offline), startsOpen: true)
        #expect(await restore(failed).run() == .failed(.offline))
    }
}
