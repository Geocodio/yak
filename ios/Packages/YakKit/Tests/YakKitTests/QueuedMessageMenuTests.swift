import Testing

@testable import YakKit

@Test func theQueuedMessageMenuHidesEditWhenTheTextCannotChange() {
    #expect(QueuedMessageMenu.items(canEditText: true, isQueued: true) == [.edit, .sendNow, .remove])
    #expect(QueuedMessageMenu.items(canEditText: false, isQueued: true) == [.sendNow, .remove])
    #expect(QueuedMessageMenu.items(canEditText: false, isQueued: false) == [.sendAfterTurn, .remove])
    #expect(QueuedMessageMenu.items(canEditText: true, isQueued: false) == [.edit, .sendAfterTurn, .remove])
}
