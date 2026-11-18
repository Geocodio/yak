import Testing

@testable import YakKit

private let everything = ThreadActionFlags(
    canStop: true, canRetry: true, canCancel: true, canMove: true, moveTargets: ["example/api", "example/web"],
    canRequestReview: true, canRetryRender: true)

@Suite struct ThreadMenuTests {
    @Test func cancelIsLastAndDestructive() {
        let items = ThreadMenu.items(actions: everything, canDrive: true)
        #expect(
            items.map(\.id) == [
                "retry", "request-review", "retry-render", "move:example/api", "move:example/web", "cancel",
            ])
        #expect(items.last?.action == .cancel && items.last?.isDestructive == true)
        #expect(items.dropLast().allSatisfy { !$0.isDestructive })
        #expect(items[3].action == .move(repo: "example/api"))
    }

    @Test func stopStaysInTheComposer() {
        let onlyStop = ThreadActionFlags(canStop: true)
        #expect(ThreadMenu.items(actions: onlyStop, canDrive: true).isEmpty)
    }

    @Test func moveNeedsTargets() {
        let flagOnly = ThreadActionFlags(canMove: true, moveTargets: [])
        #expect(ThreadMenu.items(actions: flagOnly, canDrive: true).isEmpty)
        let targetsOnly = ThreadActionFlags(canMove: false, moveTargets: ["example/web"])
        #expect(ThreadMenu.items(actions: targetsOnly, canDrive: true).isEmpty)
    }

    @Test func aReaderGetsNoItems() {
        #expect(ThreadMenu.items(actions: everything, canDrive: false).isEmpty)
        #expect(ThreadMenu.items(show: nil, isForbidden: false).isEmpty)
    }

    @Test func settingUpReplacesRetryWhileTheRepositoryIsNotSetUp() {
        let items = ThreadMenu.items(actions: everything, canDrive: true, isWaitingForSetup: true)
        #expect(items.first?.action == .setUpAndContinue && !items.contains { $0.action == .retry })
    }

    @Test func theShowFeedsTheMenu() throws {
        let show = try workShow([
            "actions": #"{"canRetry":true,"canMove":true,"moveTargets":["example/web"],"canCancel":true}"#
        ])
        #expect(ThreadMenu.items(show: show, isForbidden: false).map(\.id) == ["retry", "move:example/web", "cancel"])
        #expect(ThreadMenu.items(show: show, isForbidden: true).isEmpty)
    }
}
