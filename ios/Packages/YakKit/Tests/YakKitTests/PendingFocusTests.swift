import Foundation
import Testing

@testable import YakKit

private func target(_ thread: String = "d0e515ef", _ fragment: String = "card-question") -> PendingFocus {
    PendingFocus(threadID: thread, fragment: fragment)!
}

@Test func aTargetNeedsAThreadIDAndAFragmentThePageAccepts() {
    #expect(PendingFocus(threadID: "d0e515ef", fragment: "message-9f2c1a4e") != nil)
    #expect(PendingFocus(threadID: "d0e515ef", fragment: nil) == nil)
    #expect(PendingFocus(threadID: "d0e515ef", fragment: "a b") == nil)
    #expect(PendingFocus(threadID: "short", fragment: "card-question") == nil)
}

@Test func aTargetIsHandedOutOnceAndThenCleared() {
    var slot = PendingFocusSlot()
    slot.set(target())
    #expect(slot.take(forThread: "d0e515ef") == target())
    #expect(slot.take(forThread: "d0e515ef") == nil)
    #expect(slot.target == nil)
}

@Test func aTargetForAnotherThreadStaysUntilItsThreadAsks() {
    var slot = PendingFocusSlot()
    slot.set(target("d0e515ef"))
    #expect(slot.take(forThread: "a1b2c3d4") == nil)
    #expect(slot.target == target("d0e515ef"))
    #expect(slot.take(forThread: "d0e515ef") != nil)
}

@Test func aLaterTargetReplacesAPendingOne() {
    var slot = PendingFocusSlot()
    slot.set(target("d0e515ef", "card-question"))
    slot.set(target("a1b2c3d4", "message-9f2c1a4e"))
    #expect(slot.take(forThread: "d0e515ef") == nil)
    #expect(slot.take(forThread: "a1b2c3d4") == target("a1b2c3d4", "message-9f2c1a4e"))
}

@Test func aLinkWithoutATargetClearsAPendingOne() {
    var slot = PendingFocusSlot()
    slot.set(target())
    slot.set(nil)
    #expect(slot.target == nil)
}

@Test func leavingTheThreadDropsItsTargetButNotTheOneOfTheThreadShown() {
    var slot = PendingFocusSlot()
    slot.set(target("d0e515ef"))
    slot.drop(unlessForThread: "d0e515ef")
    #expect(slot.target != nil)
    slot.drop(unlessForThread: "a1b2c3d4")
    #expect(slot.target == nil)
    slot.set(target())
    slot.drop(unlessForThread: nil)
    #expect(slot.target == nil)
}
