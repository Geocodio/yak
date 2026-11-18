import CoreGraphics
import Testing
import YakKit

@testable import Yak

private func inputs(width: Double, compact: Bool = false) -> LayoutInputs {
    LayoutInputs(isCompactWidth: compact, isCompactHeight: false, detailWidth: width, hasVerticalToolbarEdge: false)
}

private let panes: [PaneID] = [.chat, .preview]
private let state = PaneState(focused: .chat, second: .preview)

@MainActor private func plan(_ width: Double, compact: Bool = false) -> SlotPlan {
    PaneLayoutRules.plan(state, available: panes, inputs: inputs(width: width, compact: compact))
}

@MainActor @Test func aPaneMovingBetweenSlotsKeepsItsView() {
    let model = PaneSlotsModel()
    for width in [900.0, 600, 300, 900] {
        model.show(plan(width, compact: width == 300))
        #expect(model.alive == [.chat, .preview])
    }
    model.show(plan(900))
    model.report(CGRect(x: 0, y: 0, width: 400, height: 600), for: .leading)
    model.report(CGRect(x: 406, y: 0, width: 400, height: 600), for: .trailing)
    #expect(model.frame(for: .chat)?.minX == 0)
    #expect(model.frame(for: .preview)?.minX == 406)
    model.show(plan(600))
    model.report(CGRect(x: 0, y: 0, width: 600, height: 300), for: .upper)
    #expect(model.frame(for: .preview)?.height == 300)
}

@MainActor @Test func aHiddenPaneHasNoFrame() {
    let model = PaneSlotsModel()
    model.show(plan(300, compact: true))
    model.report(CGRect(x: 0, y: 0, width: 300, height: 600), for: .single)
    #expect(model.frame(for: .chat) != nil)
    #expect(model.frame(for: .preview) == nil && !model.isShown(.preview))
    model.show(plan(900))
    model.report(CGRect(x: 0, y: 0, width: 400, height: 600), for: .leading)
    model.report(CGRect(x: 406, y: 0, width: 400, height: 600), for: .trailing)
    model.clear(.trailing)
    #expect(model.frame(for: .preview) == nil && model.alive.contains(.preview))
    model.show(plan(900))
    #expect(model.frame(for: .preview) != nil)
}

@MainActor @Test func closingTheThreadReleasesEveryPane() {
    let model = PaneSlotsModel()
    model.show(plan(900))
    model.threadClosed()
    #expect(model.alive.isEmpty && model.frames.isEmpty && !model.isShown(.chat))
}

@MainActor @Test func framesFromAnUnknownSlotAreIgnored() {
    let model = PaneSlotsModel()
    model.show(plan(900))
    model.report(CGRect(x: 0, y: 0, width: 400, height: 600), for: .upper)
    model.report(CGRect(x: Double.nan, y: 0, width: 400, height: 600), for: .leading)
    #expect(model.frame(for: .chat) == nil && model.frame(for: .preview) == nil)
}

@MainActor private func posePlan(fold: FoldAxis?, keyboard: Bool = false) -> SlotPlan {
    let inputs = LayoutInputs(
        isCompactWidth: false, isCompactHeight: false, detailWidth: 900, hasVerticalToolbarEdge: true,
        posture: .partlyOpen, fold: fold, isKeyboardOnLowerHalf: keyboard)
    return PaneLayoutRules.plan(state, available: panes, inputs: inputs)
}

@MainActor @Test func aPoseChangeKeepsEveryPaneAlive() {
    let model = PaneSlotsModel()
    let steps = [plan(900), posePlan(fold: .vertical), posePlan(fold: .horizontal), plan(300, compact: true)]
    for step in steps {
        model.show(step)
        #expect(model.alive == [.chat, .preview])
    }
    #expect(posePlan(fold: .vertical).arrangement == .book)
    #expect(posePlan(fold: .horizontal).arrangement == .laptop)
}

@MainActor @Test func theKeyboardInTheLaptopPoseHidesOnlyTheOtherPane() {
    let model = PaneSlotsModel()
    let laptop = posePlan(fold: .horizontal, keyboard: true)
    #expect(laptop.arrangement == .laptop)
    model.show(laptop)
    for slot in laptop.slots { model.report(CGRect(x: 0, y: 0, width: 400, height: 300), for: slot.position) }
    for slot in laptop.slots where slot.position != laptop.focusedPosition { model.clear(slot.position) }
    #expect(model.frame(for: .chat) != nil)
    #expect(model.frame(for: .preview) == nil && Set(model.alive) == [.chat, .preview])
    model.show(laptop)
    #expect(model.frame(for: .preview) != nil)
}

@MainActor @Test func theHingeStateKeepsTheLastDivisionAndSeesTheKeyboard() {
    let hinge = HingeState()
    hinge.setPosture(.partlyOpen)
    hinge.setDivision(CGRect(x: 0, y: 400, width: 800, height: 40), isActive: true)
    #expect(hinge.fold == .horizontal)
    hinge.setKeyboard(CGRect(x: 0, y: 420, width: 800, height: 300))
    hinge.setDivision(nil, isActive: false)
    #expect(hinge.fold == nil && hinge.lastDivision != nil && hinge.isKeyboardOnLowerHalf)
    hinge.setKeyboard(.zero)
    #expect(!hinge.isKeyboardOnLowerHalf)
}

@MainActor @Test func onePaneIsShownByTheFocusedPaneNotByThePlacementOfTheLastUpdate() {
    // The placement is written one update after the focus changes; with one slot the switch must not wait for it.
    #expect(PaneHost.isShown(.preview, focused: .preview, isSingle: true, placed: false))
    #expect(!PaneHost.isShown(.chat, focused: .preview, isSingle: true, placed: true))
    // With two slots the placement decides.
    #expect(PaneHost.isShown(.chat, focused: .preview, isSingle: false, placed: true))
    #expect(!PaneHost.isShown(.preview, focused: .preview, isSingle: false, placed: false))
}

@MainActor @Test func aSlotThatLeftThePlanLosesItsFrame() {
    let model = PaneSlotsModel()
    model.show(plan(900))
    model.report(CGRect(x: 0, y: 0, width: 400, height: 600), for: .leading)
    model.report(CGRect(x: 406, y: 0, width: 400, height: 600), for: .trailing)
    model.show(plan(300, compact: true))
    #expect(model.frames[.leading] == nil && model.frames[.trailing] == nil)
}

@MainActor @Test func aHingeChangeReadsTheFoldRegionAgainWithoutASizeChange() {
    let hinge = HingeState()
    #expect(hinge.fold == nil && hinge.posture == .flat)
    // The fake hinge: the system reports the region only once the hinge is half open.
    hinge.hingeChanged(to: .partlyOpen) { (CGRect(x: 390, y: 0, width: 40, height: 800), true) }
    #expect(hinge.posture == .partlyOpen)
    #expect(hinge.fold != nil)
    hinge.hingeChanged(to: .flat) { (CGRect(x: 390, y: 0, width: 40, height: 800), false) }
    #expect(hinge.posture == .flat && hinge.fold == nil)
}

@MainActor @Test func theDividerStripIsAsTallAsItsTouchTargetAndSteppedByTenPercent() {
    #expect(PaneDivider.bandHeight >= 44)
    #expect(PaneDivider.adjusted(0.5, increment: true) == 0.6)
    #expect(PaneDivider.adjusted(0.5, increment: false) == 0.4)
    #expect(PaneDivider.adjusted(0.7, increment: true) == 0.7)
    #expect(PaneDivider.adjusted(0.3, increment: false) == 0.3)
}

@MainActor @Test func aPrivateThreadsWindowTitleIsNeverItsRealTitle() {
    #expect(WindowTitle.privateThread.text == "A private thread")
    #expect(WindowTitle.thread("Fix the import").text == "Fix the import")
    #expect(WindowTitle.none.text == "")
}
