import Foundation
import Testing

@testable import YakKit

private let all = PaneID.allCases
private let wide = LayoutInputs(
    isCompactWidth: false, isCompactHeight: false, detailWidth: 867, hasVerticalToolbarEdge: true)
private let upright = LayoutInputs(
    isCompactWidth: false, isCompactHeight: false, detailWidth: 669, hasVerticalToolbarEdge: false)
private let outer = LayoutInputs(
    isCompactWidth: true, isCompactHeight: false, detailWidth: 466, hasVerticalToolbarEdge: true)
private let ipad = LayoutInputs(
    isCompactWidth: false, isCompactHeight: false, detailWidth: 900, hasVerticalToolbarEdge: false)
private let laptopInputs = LayoutInputs(
    isCompactWidth: false, isCompactHeight: false, detailWidth: 669, hasVerticalToolbarEdge: true,
    posture: .partlyOpen, fold: .horizontal)
private let bookInputs = LayoutInputs(
    isCompactWidth: false, isCompactHeight: false, detailWidth: 867, hasVerticalToolbarEdge: true,
    posture: .partlyOpen, fold: .vertical)

@Suite struct PaneLayoutRulesTests {
    @Test func unfoldingAddsTheLastSecondPaneAndFoldingKeepsTheFocusedOne() {
        var state = PaneState(focused: .chat, second: .preview)
        state = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: state, available: all, inputs: outer)
        #expect(state == PaneState(focused: .chat, second: nil, lastSecond: .preview))
        state = PaneLayoutRules.arrangementChanged(
            from: .single, to: .sideBySide, state: state, available: all, inputs: wide)
        #expect(state.focused == .chat && state.second == .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        #expect(
            plan.slots == [
                PaneSlot(position: .leading, content: .pane(.chat)),
                PaneSlot(position: .trailing, content: .pane(.preview)),
            ])
    }

    @Test func foldingWhileTheSecondPaneHasFocusKeepsIt() {
        let state = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: PaneState(focused: .preview, second: .chat, secondLeads: true),
            available: all, inputs: outer)
        #expect(state.focused == .preview && state.second == nil && state.lastSecond == .chat)
    }

    @Test func theBoardsPutChatLeadingAndLower() {
        let state = PaneState(focused: .chat, second: .preview)
        let side = PaneLayoutRules.plan(state, available: all, inputs: wide)
        #expect(side.arrangement == .sideBySide)
        #expect(side.slots.map(\.position) == [.leading, .trailing])
        #expect(side.slots.map(\.content) == [.pane(.chat), .pane(.preview)])
        #expect(side.bars == [.leading: nil, .trailing: nil])
        #expect(side.upperFraction == nil)

        let stacked = PaneLayoutRules.plan(state, available: all, inputs: upright)
        #expect(stacked.slots.map(\.position) == [.upper, .lower])
        #expect(stacked.slots.map(\.content) == [.pane(.preview), .pane(.chat)])
        #expect(stacked.bars == [.lower: .barRow])
        #expect(stacked.focusedPosition == .lower)
        #expect(stacked.upperFraction == 0.5)

        let pad = PaneLayoutRules.plan(PaneState(focused: .chat, second: .terminal), available: all, inputs: ipad)
        #expect(pad.bars == [.leading: .menuButton, .trailing: .menuButton])
        #expect(pad.slots.map(\.content) == [.pane(.chat), .pane(.terminal)])
    }

    @Test func focusingTheOtherSlotMovesNothing() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let next = PaneLayoutRules.focus(.trailing, state: state, plan: plan)
        #expect(next.focused == .preview && next.second == .chat)
        #expect(PaneLayoutRules.plan(next, available: all, inputs: wide).slots == plan.slots)
        let stacked = PaneLayoutRules.plan(state, available: all, inputs: upright)
        let back = PaneLayoutRules.focus(.upper, state: state, plan: stacked)
        #expect(PaneLayoutRules.plan(back, available: all, inputs: upright).slots == stacked.slots)
        #expect(PaneLayoutRules.focus(.leading, state: state, plan: plan) == state)
    }

    @Test func aPanePickedInABarsMenuStaysOnThatSide() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let next = PaneLayoutRules.select(.terminal, from: .trailing, state: state, plan: plan)
        #expect(next.focused == .terminal && next.second == .chat)
        #expect(
            PaneLayoutRules.plan(next, available: all, inputs: wide).slots.map(\.content)
                == [.pane(.chat), .pane(.terminal)])
        let leading = PaneLayoutRules.select(.terminal, from: .leading, state: state, plan: plan)
        #expect(
            PaneLayoutRules.plan(leading, available: all, inputs: wide).slots.map(\.content)
                == [.pane(.terminal), .pane(.preview)])
    }

    @Test func pickingAShownPaneOnlyFocusesIt() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let next = PaneLayoutRules.select(.preview, from: .leading, state: state, plan: plan)
        #expect(next.focused == .preview)
        #expect(
            PaneLayoutRules.plan(next, available: all, inputs: wide).slots.map(\.content)
                == [.pane(.chat), .pane(.preview)])
    }

    @Test func aPaneThatIsNotAvailableChangesNothing() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: [.chat, .preview], inputs: wide)
        #expect(PaneLayoutRules.select(.terminal, from: nil, state: state, plan: plan) == state)
    }

    @Test func theOverviewIsAPanelAtWideWidthsAndAPaneBelow() {
        var state = PaneState(focused: .overview, second: .preview)
        var plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        #expect(
            plan.slots == [
                PaneSlot(position: .leading, content: .pane(.preview)),
                PaneSlot(position: .trailing, content: .overviewPanel),
            ])
        #expect(plan.bars == [.leading: nil])
        state = PaneLayoutRules.availableChanged(state: state, available: all, inputs: wide)
        #expect(state.isOverviewPanelOpen && state.focused == .preview && state.second != .preview)

        let narrow = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: state, available: all, inputs: upright)
        #expect(narrow.focused == .overview && !narrow.isOverviewPanelOpen)

        plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
        #expect(!closed.isOverviewPanelOpen)
        #expect(PaneLayoutRules.plan(closed, available: all, inputs: wide).slots.count == 2)
        #expect(!PaneLayoutRules.plan(closed, available: all, inputs: wide).shownPanes.contains(.overview))
    }

    @Test func laptopPutsChatBelow() {
        let state = PaneState(focused: .chat, second: .preview, secondLeads: true)
        let next = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .laptop, state: state, available: all, inputs: laptopInputs)
        #expect(!next.secondLeads)
        let plan = PaneLayoutRules.plan(next, available: all, inputs: laptopInputs)
        #expect(plan.arrangement == .laptop && plan.focusedPosition == .lower)
        #expect(plan.upperFraction == nil)

        let other = PaneState(focused: .preview, second: .chat)
        let moved = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .laptop, state: other, available: all, inputs: laptopInputs)
        #expect(moved.secondLeads)
        let book = PaneLayoutRules.plan(PaneState(focused: .chat, second: .preview), available: all, inputs: bookInputs)
        #expect(book.arrangement == .book && book.slots.map(\.position) == [.leading, .trailing])
    }

    @Test func aHiddenPaneIsReplaced() {
        let state = PaneState(focused: .chat, second: .preview)
        let withoutPreview: [PaneID] = [.chat, .files, .terminal, .overview]
        let next = PaneLayoutRules.availableChanged(state: state, available: withoutPreview, inputs: wide)
        #expect(next.second == .files)
        let gone = PaneLayoutRules.availableChanged(
            state: PaneState(focused: .terminal, second: .chat, lastSecond: .terminal), available: [.chat, .preview],
            inputs: wide)
        #expect(gone.focused == .chat && gone.second == .preview && gone.lastSecond == nil)
    }

    @Test(arguments: [wide, upright, outer, laptopInputs])
    func onlyChatIsOnePaneEverywhere(inputs: LayoutInputs) {
        let plan = PaneLayoutRules.plan(PaneState(focused: .chat, second: .preview), available: [.chat], inputs: inputs)
        #expect(plan.arrangement == .single)
        #expect(plan.slots == [PaneSlot(position: .single, content: .pane(.chat))])
    }

    @Test func chatAndTheOverviewPanelShareTheWidth() {
        let available: [PaneID] = [.chat, .overview]
        let open = PaneState(focused: .chat, isOverviewPanelOpen: true)
        let plan = PaneLayoutRules.plan(open, available: available, inputs: wide)
        #expect(plan.arrangement == .sideBySide)
        #expect(plan.slots.map(\.content) == [.pane(.chat), .overviewPanel])
        #expect(plan.bars.count == 1 && plan.bars.keys.contains(.leading))

        let closed = PaneLayoutRules.plan(PaneState(), available: available, inputs: wide)
        #expect(closed.arrangement == .single)
        #expect(closed.slots == [PaneSlot(position: .single, content: .pane(.chat))])
        let opened = PaneLayoutRules.select(.overview, from: nil, state: PaneState(), plan: closed)
        #expect(opened.isOverviewPanelOpen && opened.focused == .chat)
    }

    @Test func theOverviewIsAPaneWhenTheHeightIsCompact() {
        let inputs = LayoutInputs(
            isCompactWidth: false, isCompactHeight: true, detailWidth: 932, hasVerticalToolbarEdge: false)
        let available: [PaneID] = [.chat, .overview]
        #expect(PaneLayoutRules.overviewIsPanel(arrangement: .single, inputs: inputs) == false)
        let plan = PaneLayoutRules.plan(PaneState(), available: available, inputs: inputs)
        let next = PaneLayoutRules.select(.overview, from: nil, state: PaneState(), plan: plan)
        #expect(next.focused == .overview && !next.isOverviewPanelOpen)
        #expect(
            PaneLayoutRules.plan(next, available: available, inputs: inputs).slots
                == [PaneSlot(position: .single, content: .pane(.overview))])
    }

    @Test func theKeyboardInTheLaptopPoseChangesNoPaneState() {
        let state = PaneState(focused: .chat, second: .preview)
        var typing = laptopInputs
        typing.isKeyboardOnLowerHalf = true
        typing.fold = nil
        #expect(ThreadLayout.arrangement(laptopInputs, slotPaneCount: 5) == .laptop)
        #expect(ThreadLayout.arrangement(typing, slotPaneCount: 5) == .laptop)
        let before = PaneLayoutRules.plan(state, available: all, inputs: laptopInputs)
        let during = PaneLayoutRules.plan(state, available: all, inputs: typing)
        #expect(before == during)
        #expect(
            before.slots == [
                PaneSlot(position: .upper, content: .pane(.preview)), PaneSlot(position: .lower, content: .pane(.chat)),
            ])
        let kept = PaneLayoutRules.arrangementChanged(
            from: .laptop, to: .laptop, state: state, available: all, inputs: typing)
        #expect(kept == state)
        let closed = PaneLayoutRules.arrangementChanged(
            from: .laptop, to: .laptop, state: kept, available: all, inputs: laptopInputs)
        #expect(closed == state)
        #expect(PaneLayoutRules.plan(closed, available: all, inputs: laptopInputs) == before)
    }

    @Test func foldingOrRotatingAwayFromAnOpenPanelKeepsThePaneBesideIt() {
        let start = PaneState(focused: .preview, second: .chat, isOverviewPanelOpen: true)
        let startShown = PaneLayoutRules.plan(start, available: all, inputs: wide).slots
        #expect(
            startShown == [
                PaneSlot(position: .leading, content: .pane(.preview)),
                PaneSlot(position: .trailing, content: .overviewPanel),
            ])
        let upended = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: start, available: all, inputs: upright)
        #expect(
            PaneLayoutRules.plan(upended, available: all, inputs: upright).shownPanes.sorted {
                $0.rawValue < $1.rawValue
            } == [.overview, .preview])
        let back = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .sideBySide, state: upended, available: all, inputs: wide)
        #expect(PaneLayoutRules.plan(back, available: all, inputs: wide).slots == startShown)
        let folded = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: start, available: all, inputs: outer)
        #expect(PaneLayoutRules.plan(folded, available: all, inputs: outer).shownPanes == [.overview])
        let unfolded = PaneLayoutRules.arrangementChanged(
            from: .single, to: .sideBySide, state: folded, available: all, inputs: wide)
        #expect(PaneLayoutRules.plan(unfolded, available: all, inputs: wide).slots == startShown)
    }

    @Test func defaultSecondOrder() {
        #expect(PaneLayoutRules.defaultSecond(focused: .chat, lastSecond: .terminal, candidates: all) == .terminal)
        #expect(PaneLayoutRules.defaultSecond(focused: .files, lastSecond: nil, candidates: all) == .chat)
        #expect(PaneLayoutRules.defaultSecond(focused: .chat, lastSecond: nil, candidates: all) == .preview)
        #expect(PaneLayoutRules.defaultSecond(focused: .chat, lastSecond: .chat, candidates: [.chat]) == nil)
        #expect(
            PaneLayoutRules.defaultSecond(focused: .chat, lastSecond: .files, candidates: [.chat, .preview]) == .preview
        )
    }

    @Test func marksFollowTheSlots() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let marks = PaneLayoutRules.marks(state: state, plan: plan)
        #expect(marks[.chat] == .focused && marks[.preview] == .second)
        #expect(marks[.files] == PaneMark.none && marks[.terminal] == PaneMark.none)
        let single = PaneLayoutRules.plan(state, available: all, inputs: outer)
        let oneMarks = PaneLayoutRules.marks(state: state, plan: single)
        #expect(oneMarks.values.filter { $0 != .none } == [.focused])
    }

    @Test func stripKeepsChatTheFocusedAndTheSecondLongest() {
        let state = PaneState(focused: .terminal, second: .chat)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let priorities = PaneLayoutRules.stripPriorities(available: all, state: state, plan: plan)
        #expect(priorities == [.chat: 100, .terminal: 90, .files: 40, .preview: 30, .overview: 20])
        let some = PaneLayoutRules.stripPriorities(available: [.chat, .files], state: state, plan: plan)
        let panelState = PaneState(focused: .chat, isOverviewPanelOpen: true)
        let panelPlan = PaneLayoutRules.plan(panelState, available: all, inputs: wide)
        let panelPriorities = PaneLayoutRules.stripPriorities(available: all, state: panelState, plan: panelPlan)
        #expect(panelPriorities[.overview] == 90)
        #expect(some.keys.sorted { $0.rawValue < $1.rawValue } == [.chat, .files])
    }

    @Test(arguments: [Double.nan, .infinity, -4, 0, 0.29, 0.5, 0.71, 2])
    func fractionsAreClamped(value: Double) {
        let result = PaneLayoutRules.clampedFraction(value)
        #expect(PaneLayoutRules.stackedFractionRange.contains(result))
        if !value.isFinite { #expect(result == 0.5) }
        let plan = PaneLayoutRules.plan(
            PaneState(second: .preview, stackedFraction: value), available: all, inputs: upright)
        #expect(plan.upperFraction == result)
    }

    @Test func storedStateThatContradictsItselfIsRepaired() throws {
        let same = PaneLayoutRules.availableChanged(
            state: PaneState(focused: .preview, second: .preview), available: all, inputs: outer)
        #expect(same.second == nil)
        let json = #"{"focused":"hologram","second":"preview","stackedFraction":0.4}"#
        let decoded = try JSONDecoder().decode(PaneState.self, from: Data(json.utf8))
        #expect(decoded == PaneState(focused: .chat, second: .preview, stackedFraction: 0.4))
        let empty = try JSONDecoder().decode(PaneState.self, from: Data("{}".utf8))
        #expect(empty == PaneState())
        let roundTrip = PaneState(focused: .files, second: .chat, secondLeads: true, lastSecond: .terminal)
        let data = try JSONEncoder().encode(roundTrip)
        #expect(try JSONDecoder().decode(PaneState.self, from: data) == roundTrip)
    }

    @Test func nonFiniteWidthsGiveOnePane() {
        let inputs = LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: .nan, hasVerticalToolbarEdge: false)
        let plan = PaneLayoutRules.plan(PaneState(second: .preview), available: all, inputs: inputs)
        #expect(plan.arrangement == .single)
    }

    @Test func everyArrangementAndPaneSetGivesValidSlots() {
        let sizes: [LayoutInputs] = [
            wide, upright, outer, ipad, laptopInputs, bookInputs,
            LayoutInputs(
                isCompactWidth: false, isCompactHeight: false, detailWidth: 466, hasVerticalToolbarEdge: true,
                posture: .closed),
            LayoutInputs(isCompactWidth: false, isCompactHeight: true, detailWidth: 932, hasVerticalToolbarEdge: false),
            LayoutInputs(isCompactWidth: false, isCompactHeight: true, detailWidth: 932, hasVerticalToolbarEdge: true),
            LayoutInputs(
                isCompactWidth: false, isCompactHeight: false, detailWidth: 759.5, hasVerticalToolbarEdge: false),
            LayoutInputs(
                isCompactWidth: false, isCompactHeight: false, detailWidth: 760, hasVerticalToolbarEdge: false),
            LayoutInputs(
                isCompactWidth: false, isCompactHeight: false, detailWidth: .nan, hasVerticalToolbarEdge: false),
        ]
        let others = PaneID.allCases.filter { $0 != .chat }
        for mask in 0..<(1 << others.count) {
            let available = [PaneID.chat] + others.enumerated().filter { mask & (1 << $0.offset) != 0 }.map(\.element)
            for inputs in sizes {
                for panelOpen in [false, true] {
                    for focused in available {
                        for second in [nil] + available.map({ Optional($0) }) {
                            let state = PaneState(focused: focused, second: second, isOverviewPanelOpen: panelOpen)
                            let plan = PaneLayoutRules.plan(state, available: available, inputs: inputs)
                            let shown = plan.shownPanes
                            #expect(Set(shown).count == shown.count)
                            #expect(
                                shown.contains(focused)
                                    || (focused == .overview && plan.slots.contains { $0.content == .overviewPanel })
                                    || (panelOpen && shown.contains(.overview)))
                            #expect(shown.allSatisfy(available.contains))
                            #expect((plan.slots.count == 1) == (plan.arrangement == .single))
                            if available.contains(.overview) {
                                let reachable =
                                    shown.contains(.overview) || plan.slots.contains { $0.content == .overviewPanel }
                                    || PaneLayoutRules.plan(
                                        PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan),
                                        available: available, inputs: inputs
                                    ).slots.contains { $0.content == .pane(.overview) || $0.content == .overviewPanel }
                                #expect(reachable)
                            }
                        }
                    }
                }
            }
        }
    }

    @Test func aFoldAndAnUnfoldReturnTheSameShownPanes() {
        let others = PaneID.allCases.filter { $0 != .chat }
        for mask in 0..<(1 << others.count) {
            let available = [PaneID.chat] + others.enumerated().filter { mask & (1 << $0.offset) != 0 }.map(\.element)
            for focused in available {
                for panelOpen in [false, true] {
                    let state = PaneState(focused: focused, isOverviewPanelOpen: panelOpen)
                    let shown = Set(
                        PaneLayoutRules.plan(state, available: available, inputs: wide).slots.map(\.content))
                    for (middle, middleInputs) in [(PaneArrangement.single, outer), (.stacked, upright)] {
                        let there = PaneLayoutRules.arrangementChanged(
                            from: .sideBySide, to: middle, state: state, available: available, inputs: middleInputs)
                        let back = PaneLayoutRules.arrangementChanged(
                            from: middle, to: .sideBySide, state: there, available: available, inputs: wide)
                        let again = Set(
                            PaneLayoutRules.plan(back, available: available, inputs: wide).slots.map(\.content))
                        if available.count > 1 {
                            #expect(again == shown, "\(available) \(focused) \(panelOpen) \(middle)")
                        }
                    }
                }
            }
        }
    }
}
