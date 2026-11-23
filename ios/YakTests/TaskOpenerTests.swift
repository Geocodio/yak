import Foundation
import Testing
import YakKit

@testable import Yak

/// Holds each lookup until the test lets it answer.
@MainActor private final class Gate {
    private var waiting: [String: [CheckedContinuation<TaskTarget?, Never>]] = [:]
    var hasClient = true

    func lookup(_ id: String) async -> TaskTarget? {
        guard hasClient else { return nil }
        return await withCheckedContinuation { waiting[id, default: []].append($0) }
    }

    func answer(_ id: String, with target: TaskTarget?) {
        for continuation in waiting.removeValue(forKey: id) ?? [] { continuation.resume(returning: target) }
    }
}

@MainActor private final class Rig {
    let gate = Gate()
    var threads: [String] = []
    var pages: [String] = []
    var pageIsSafe = true
    var opener: TaskOpener!

    init() {
        opener = TaskOpener(
            resolve: { [gate] in await gate.lookup($0) },
            openThread: { [unowned self] in threads.append($0) },
            openWebPage: { [unowned self] in
                pages.append($0)
                return pageIsSafe
            })
    }

    func settle() async { for _ in 0..<20 { await Task.yield() } }
}

@MainActor @Test func aRequestBeforeTheSessionIsActiveWaitsAndThenOpens() async {
    let rig = Rig()
    rig.gate.hasClient = false
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    #expect(rig.threads.isEmpty && rig.opener.problem == nil && rig.opener.openingID == "k3m9p2qa")
    rig.gate.hasClient = true
    rig.opener.sessionBecameActive()
    await rig.settle()
    rig.gate.answer("k3m9p2qa", with: .thread("d0e515ef"))
    await rig.settle()
    #expect(rig.threads == ["d0e515ef"] && rig.opener.openingID == nil)
}

@MainActor @Test func aPersonWhoMovedOnIsNotPulledAwayLater() async {
    let rig = Rig()
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    rig.opener.cancel()
    rig.gate.answer("k3m9p2qa", with: .thread("d0e515ef"))
    await rig.settle()
    #expect(rig.threads.isEmpty && rig.opener.problem == nil && rig.opener.openingID == nil)
}

@MainActor @Test func aLateFailureAfterMovingOnShowsNoAlert() async {
    let rig = Rig()
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    rig.opener.cancel()
    rig.gate.answer("k3m9p2qa", with: .failed)
    await rig.settle()
    #expect(rig.opener.problem == nil)
}

@MainActor @Test func twoQuickTapsTheLastOneWins() async {
    let rig = Rig()
    rig.opener.open(taskID: "aaaaaaaa")
    await rig.settle()
    rig.opener.open(taskID: "bbbbbbbb")
    await rig.settle()
    rig.gate.answer("bbbbbbbb", with: .thread("thread-b"))
    await rig.settle()
    rig.gate.answer("aaaaaaaa", with: .thread("thread-a"))
    await rig.settle()
    #expect(rig.threads == ["thread-b"])
}

@MainActor @Test func theAlertPathsAreTheirOwn() async {
    let rig = Rig()
    for (target, expected) in [(TaskTarget.notFound, TaskTarget.notFound), (.failed, .failed), (.offline, .offline)] {
        rig.opener.open(taskID: "k3m9p2qa")
        await rig.settle()
        rig.gate.answer("k3m9p2qa", with: target)
        await rig.settle()
        #expect(rig.opener.problem == expected)
        rig.opener.problem = nil
    }
    #expect(rig.threads.isEmpty && rig.pages.isEmpty)
}

@MainActor @Test func aTaskWithoutAThreadOpensItsWebPageAndAnUnsafeOneFails() async {
    let rig = Rig()
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    rig.gate.answer("k3m9p2qa", with: .webPage)
    await rig.settle()
    #expect(rig.pages == ["k3m9p2qa"] && rig.opener.problem == nil)
    rig.pageIsSafe = false
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    rig.gate.answer("k3m9p2qa", with: .webPage)
    await rig.settle()
    #expect(rig.opener.problem == .failed)
}

@MainActor @Test func aRevokedDeviceShowsNoAlert() async {
    let rig = Rig()
    rig.opener.open(taskID: "k3m9p2qa")
    await rig.settle()
    rig.gate.answer("k3m9p2qa", with: .needsPairing)
    await rig.settle()
    #expect(rig.opener.problem == nil && rig.threads.isEmpty && rig.opener.openingID == nil)
}
