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
