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
            from: .sideBySide, to: .single, state: state, available: all, inputs: outer, oldInputs: wide)
        #expect(state == PaneState(focused: .chat, second: .preview))
        state = PaneLayoutRules.arrangementChanged(
            from: .single, to: .sideBySide, state: state, available: all, inputs: wide, oldInputs: outer)
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
            available: all, inputs: outer, oldInputs: wide)
        #expect(state.focused == .preview && state.second == .chat && state.secondLeads)
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
        #expect(state.isOverviewInPair && state.second == .preview)

        let narrow = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: state, available: all, inputs: upright, oldInputs: wide)
        #expect(narrow.focused == .overview && narrow.second == .preview)

        plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
        #expect(!closed.isOverviewInPair)
        #expect(PaneLayoutRules.plan(closed, available: all, inputs: wide).slots.count == 1)
        #expect(!PaneLayoutRules.plan(closed, available: all, inputs: wide).shownPanes.contains(.overview))
    }

    @Test func laptopPutsChatBelow() {
        let state = PaneState(focused: .chat, second: .preview, secondLeads: true)
        let next = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .laptop, state: state, available: all, inputs: laptopInputs, oldInputs: upright)
        #expect(next.secondLeads)
        let plan = PaneLayoutRules.plan(next, available: all, inputs: laptopInputs)
        #expect(plan.arrangement == .laptop && plan.focusedPosition == .lower)
        #expect(plan.slots.map(\.content) == [.pane(.preview), .pane(.chat)])
        #expect(plan.upperFraction == nil)

        let other = PaneState(focused: .preview, second: .chat)
        let moved = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .laptop, state: other, available: all, inputs: laptopInputs, oldInputs: upright)
        let movedPlan = PaneLayoutRules.plan(moved, available: all, inputs: laptopInputs)
        #expect(
            movedPlan.slots.map(\.content) == [.pane(.preview), .pane(.chat)] && movedPlan.focusedPosition == .upper)
        let book = PaneLayoutRules.plan(PaneState(focused: .chat, second: .preview), available: all, inputs: bookInputs)
        #expect(book.arrangement == .book && book.slots.map(\.position) == [.leading, .trailing])
    }

    @Test func aHiddenPaneIsReplaced() {
        let state = PaneState(focused: .chat, second: .preview)
        let withoutPreview: [PaneID] = [.chat, .files, .terminal, .overview]
        let next = PaneLayoutRules.availableChanged(state: state, available: withoutPreview, inputs: wide)
        #expect(next.second == nil)
        let gone = PaneLayoutRules.availableChanged(
            state: PaneState(focused: .terminal, second: .chat), available: [.chat, .preview],
            inputs: wide)
        #expect(gone.focused == .chat && gone.second == nil)
    }

    @Test(arguments: [wide, upright, outer, laptopInputs])
    func onlyChatIsOnePaneEverywhere(inputs: LayoutInputs) {
        let plan = PaneLayoutRules.plan(PaneState(focused: .chat, second: .preview), available: [.chat], inputs: inputs)
        #expect(plan.arrangement == .single)
        #expect(plan.slots == [PaneSlot(position: .single, content: .pane(.chat))])
    }

    @Test func chatAndTheOverviewPanelShareTheWidth() {
        let available: [PaneID] = [.chat, .overview]
        let open = PaneState(focused: .chat, second: .overview)
        let plan = PaneLayoutRules.plan(open, available: available, inputs: wide)
        #expect(plan.arrangement == .sideBySide)
        #expect(plan.slots.map(\.content) == [.pane(.chat), .overviewPanel])
        #expect(plan.bars.count == 1 && plan.bars.keys.contains(.leading))

        let closed = PaneLayoutRules.plan(PaneState(), available: available, inputs: wide)
        #expect(closed.arrangement == .single)
        #expect(closed.slots == [PaneSlot(position: .single, content: .pane(.chat))])
        let opened = PaneLayoutRules.select(.overview, from: nil, state: PaneState(), plan: closed)
        #expect(opened.second == .overview && opened.focused == .chat)
    }

    @Test func theOverviewIsAPaneWhenTheHeightIsCompact() {
        let inputs = LayoutInputs(
            isCompactWidth: false, isCompactHeight: true, detailWidth: 932, hasVerticalToolbarEdge: false)
        let available: [PaneID] = [.chat, .overview]
        #expect(PaneLayoutRules.overviewIsPanel(arrangement: .single, inputs: inputs) == false)
        let plan = PaneLayoutRules.plan(PaneState(), available: available, inputs: inputs)
        let next = PaneLayoutRules.select(.overview, from: nil, state: PaneState(), plan: plan)
        #expect(next.focused == .overview)
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
            from: .laptop, to: .laptop, state: state, available: all, inputs: typing, oldInputs: laptopInputs)
        #expect(kept == state)
        let closed = PaneLayoutRules.arrangementChanged(
            from: .laptop, to: .laptop, state: kept, available: all, inputs: laptopInputs, oldInputs: typing)
        #expect(closed == state)
        #expect(PaneLayoutRules.plan(closed, available: all, inputs: laptopInputs) == before)
    }

    @Test func aShortWideSizeHasNoPanelToFocusWhenItGoesStacked() {
        let shortWide = LayoutInputs(
            isCompactWidth: false, isCompactHeight: true, detailWidth: 867, hasVerticalToolbarEdge: true)
        let start = PaneState(focused: .chat, second: .overview)
        #expect(PaneLayoutRules.plan(start, available: all, inputs: shortWide).shownPanes == [.chat, .overview])
        let next = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: start, available: all, inputs: upright, oldInputs: shortWide)
        #expect(next.focused == .chat && next.second == .overview)
        let plan = PaneLayoutRules.plan(next, available: all, inputs: upright)
        #expect(plan.slots.map(\.content) == [.pane(.overview), .pane(.chat)] && plan.focusedPosition == .lower)
    }

    @Test func foldingOrRotatingAwayFromAnOpenPanelFocusesTheOverviewAndKeepsTheSides() {
        let start = PaneState(focused: .preview, second: .overview, behind: .chat)
        let startShown = PaneLayoutRules.plan(start, available: all, inputs: wide).slots
        #expect(
            startShown == [
                PaneSlot(position: .leading, content: .pane(.preview)),
                PaneSlot(position: .trailing, content: .overviewPanel),
            ])
        let upended = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: start, available: all, inputs: upright, oldInputs: wide)
        let upendedPlan = PaneLayoutRules.plan(upended, available: all, inputs: upright)
        #expect(
            upendedPlan.slots == [
                PaneSlot(position: .upper, content: .pane(.overview)),
                PaneSlot(position: .lower, content: .pane(.preview)),
            ])
        #expect(upendedPlan.focusedPosition == .upper)
        let back = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .sideBySide, state: upended, available: all, inputs: wide, oldInputs: upright)
        #expect(PaneLayoutRules.plan(back, available: all, inputs: wide).slots == startShown)
        let folded = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: start, available: all, inputs: outer, oldInputs: wide)
        #expect(PaneLayoutRules.plan(folded, available: all, inputs: outer).shownPanes == [.overview])
        let unfolded = PaneLayoutRules.arrangementChanged(
            from: .single, to: .sideBySide, state: folded, available: all, inputs: wide, oldInputs: outer)
        #expect(PaneLayoutRules.plan(unfolded, available: all, inputs: wide).slots == startShown)
    }

    @Test(arguments: [false, true])
    func aHiddenSecondPaneSurvivesAnOpenPanelAcrossAFoldOrARotation(secondLeads: Bool) {
        let start = PaneState(focused: .chat, second: .overview, secondLeads: secondLeads, behind: .terminal)
        func closedSlots(_ state: PaneState) -> [PaneSlot] {
            let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
            let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
            return PaneLayoutRules.plan(closed, available: all, inputs: wide).slots
        }
        let expected = closedSlots(start)
        let panes: [SlotContent] = secondLeads ? [.pane(.terminal), .pane(.chat)] : [.pane(.chat), .pane(.terminal)]
        #expect(expected.map(\.content) == panes)
        for (middle, middleInputs) in [(PaneArrangement.single, outer), (.stacked, upright)] {
            let there = PaneLayoutRules.arrangementChanged(
                from: .sideBySide, to: middle, state: start, available: all, inputs: middleInputs, oldInputs: wide)
            let back = PaneLayoutRules.arrangementChanged(
                from: middle, to: .sideBySide, state: there, available: all, inputs: wide, oldInputs: middleInputs)
            #expect(closedSlots(back) == expected, "\(middle) \(secondLeads)")
        }
    }

    @Test(arguments: [false, true])
    func anOpenPanelWithoutACoveredPaneClosesToTheDefaultSecondOnTheSameSideAfterARotation(secondLeads: Bool) {
        let start = PaneState(focused: .chat, second: .overview, secondLeads: secondLeads)
        func closedSlots(_ state: PaneState) -> [SlotContent] {
            let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
            let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
            return PaneLayoutRules.plan(closed, available: all, inputs: wide).slots.map(\.content)
        }
        let expected = closedSlots(start)
        let there = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .stacked, state: start, available: all, inputs: upright, oldInputs: wide)
        let back = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .sideBySide, state: there, available: all, inputs: wide, oldInputs: upright)
        #expect(closedSlots(back) == expected)
    }

    @Test func aGoneCoveredPaneIsForgotten() {
        let state = PaneLayoutRules.availableChanged(
            state: PaneState(focused: .chat, second: .overview, behind: .terminal),
            available: all.filter { $0 != .terminal }, inputs: wide)
        #expect(state.behind == nil && state.second == .overview)
    }

    @Test func anOverviewSecondPaneBecomesThePanelOnRotation() {
        let start = PaneState(focused: .preview, second: .overview)
        let there = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .sideBySide, state: start, available: all, inputs: wide, oldInputs: upright)
        #expect(PaneLayoutRules.plan(there, available: all, inputs: wide).slots.map(\.content).contains(.overviewPanel))
        #expect(there.focused == .preview)
    }

    @Test func atOneSlotAPickedPartnerTradesPlacesWithTheFocusedPane() {
        let open = PaneState(focused: .chat, second: .overview, behind: .files)
        var state = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: open, available: all, inputs: outer, oldInputs: wide)
        #expect(state.focused == .overview && state.second == .chat)
        var plan = PaneLayoutRules.plan(state, available: all, inputs: outer)
        state = PaneLayoutRules.select(.chat, from: nil, state: state, plan: plan)
        #expect(state.focused == .chat && state.second == .overview)
        plan = PaneLayoutRules.plan(state, available: all, inputs: outer)
        state = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
        #expect(state.focused == .overview && state.second == .chat)
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
        let panelState = PaneState(focused: .chat, second: .overview)
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

    @Test func aSlotIsNeverShorterThanItsMinimumWhereTheHeightAllowsIt() {
        // 0.3 of 400 is 120, under the 150 minimum: the share moves up.
        #expect(PaneLayoutRules.clampedFraction(0.3, availableHeight: 400, minimumSlotHeight: 150) == 0.375)
        #expect(PaneLayoutRules.clampedFraction(0.7, availableHeight: 400, minimumSlotHeight: 150) == 0.625)
        // Room to spare changes nothing; no room for two minimum slots splits evenly.
        #expect(PaneLayoutRules.clampedFraction(0.4, availableHeight: 1000, minimumSlotHeight: 150) == 0.4)
        #expect(PaneLayoutRules.clampedFraction(0.7, availableHeight: 250, minimumSlotHeight: 150) == 0.5)
        #expect(PaneLayoutRules.clampedFraction(0.7, availableHeight: .nan, minimumSlotHeight: 150) == 0.7)
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
        let roundTrip = PaneState(focused: .files, second: .chat, secondLeads: true, behind: .terminal)
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
                            let state = PaneState(focused: focused, second: panelOpen ? .overview : second)
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

    @Test func aRotationRoundTripFromStackedKeepsEverySideAndTheFocus() {
        for focused in all {
            for second in all.filter({ $0 != focused }) {
                for secondLeads in [false, true] {
                    let state = PaneLayoutRules.availableChanged(
                        state: PaneState(focused: focused, second: second, secondLeads: secondLeads),
                        available: all, inputs: upright)
                    let before = PaneLayoutRules.plan(state, available: all, inputs: upright)
                    let there = PaneLayoutRules.arrangementChanged(
                        from: .stacked, to: .sideBySide, state: state, available: all, inputs: wide, oldInputs: upright)
                    let back = PaneLayoutRules.arrangementChanged(
                        from: .sideBySide, to: .stacked, state: there, available: all, inputs: upright, oldInputs: wide)
                    let after = PaneLayoutRules.plan(back, available: all, inputs: upright)
                    let note = "\(focused) \(second) \(secondLeads)"
                    #expect(after.slots == before.slots, "\(note)")
                    // An Overview in the pair takes the focus when it turns into a pane.
                    if focused != .overview && second != .overview {
                        #expect(after.focusedPosition == before.focusedPosition, "\(note)")
                    }
                }
            }
        }
    }

    @Test(arguments: [false, true])
    func foldingFromAnOverviewPaneKeepsTheHiddenPane(secondLeads: Bool) {
        let start = PaneState(focused: .chat, second: .overview, secondLeads: secondLeads, behind: .terminal)
        var state = start
        let steps: [(PaneArrangement, PaneArrangement, LayoutInputs, LayoutInputs)] = [
            (.sideBySide, .stacked, wide, upright), (.stacked, .single, upright, outer),
            (.single, .stacked, outer, upright), (.stacked, .sideBySide, upright, wide),
        ]
        for (old, new, oldInputs, inputs) in steps {
            state = PaneLayoutRules.arrangementChanged(
                from: old, to: new, state: state, available: all, inputs: inputs, oldInputs: oldInputs)
        }
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
        let expectedPlan = PaneLayoutRules.plan(start, available: all, inputs: wide)
        let expected = PaneLayoutRules.select(.overview, from: nil, state: start, plan: expectedPlan)
        #expect(
            PaneLayoutRules.plan(closed, available: all, inputs: wide).slots
                == PaneLayoutRules.plan(expected, available: all, inputs: wide).slots)
    }

    @Test func aFoldAndAnUnfoldReturnTheSameShownPanes() {
        let others = PaneID.allCases.filter { $0 != .chat }
        func slots(_ state: PaneState, available: [PaneID], closingThePanel: Bool) -> [SlotContent] {
            var plan = PaneLayoutRules.plan(state, available: available, inputs: wide)
            if closingThePanel, plan.slots.contains(where: { $0.content == .overviewPanel }) {
                let closed = PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
                plan = PaneLayoutRules.plan(closed, available: available, inputs: wide)
            }
            return plan.slots.map(\.content)
        }
        for mask in 0..<(1 << others.count) {
            let available = [PaneID.chat] + others.enumerated().filter { mask & (1 << $0.offset) != 0 }.map(\.element)
            guard available.count > 1 else { continue }
            for focused in available {
                for second in [nil] + available.filter({ $0 != focused }).map(Optional.some) {
                    for secondLeads in [false, true] {
                        for panelOpen in [false, true] {
                            // The scene resolves a stored state before it shows it.
                            let state = PaneLayoutRules.availableChanged(
                                state: PaneState(
                                    focused: focused, second: panelOpen ? .overview : second,
                                    secondLeads: secondLeads),
                                available: available, inputs: wide)
                            let shown = slots(state, available: available, closingThePanel: true)
                            for (middle, middleInputs) in [(PaneArrangement.single, outer), (.stacked, upright)] {
                                let there = PaneLayoutRules.arrangementChanged(
                                    from: .sideBySide, to: middle, state: state, available: available,
                                    inputs: middleInputs, oldInputs: wide)
                                let back = PaneLayoutRules.arrangementChanged(
                                    from: middle, to: .sideBySide, state: there, available: available, inputs: wide,
                                    oldInputs: middleInputs)
                                let again = slots(back, available: available, closingThePanel: true)
                                let note =
                                    "\(available) \(focused) \(String(describing: second)) \(secondLeads) \(panelOpen) \(middle)"
                                if (panelOpen && available.contains(.overview)) || middle == .stacked {
                                    #expect(again == shown, "\(note)")
                                } else {
                                    #expect(Set(again) == Set(shown), "\(note)")
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    @Test func theSameVisibleStateRotatesTheSameWhateverItsHistory() {
        func changed(_ state: PaneState, _ old: PaneArrangement, _ new: PaneArrangement, _ inputs: LayoutInputs)
            -> PaneState
        {
            let oldInputs = [PaneArrangement.sideBySide: wide, .single: outer, .stacked: upright][old]!
            return PaneLayoutRules.arrangementChanged(
                from: old, to: new, state: state, available: all, inputs: inputs, oldInputs: oldInputs)
        }
        func toggledOverview(_ state: PaneState) -> PaneState {
            let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
            return PaneLayoutRules.select(.overview, from: nil, state: state, plan: plan)
        }
        var state = changed(
            PaneState(focused: .chat, second: .overview, secondLeads: true), .stacked, .sideBySide, wide)
        state = toggledOverview(toggledOverview(state))
        let fresh = PaneState(focused: .chat, second: .overview, behind: .preview)
        #expect(
            PaneLayoutRules.plan(state, available: all, inputs: wide)
                == PaneLayoutRules.plan(fresh, available: all, inputs: wide))
        let rotated = changed(state, .sideBySide, .stacked, upright)
        let expected = changed(fresh, .sideBySide, .stacked, upright)
        #expect(
            PaneLayoutRules.plan(rotated, available: all, inputs: upright).slots
                == PaneLayoutRules.plan(expected, available: all, inputs: upright).slots)
    }
}

@Suite struct OnePaneByDefaultTests {
    private let sizes: [(String, LayoutInputs)] = [
        ("sideBySide", wide), ("stacked", upright), ("single", outer), ("ipad", ipad), ("laptop", laptopInputs),
        ("book", bookInputs),
    ]

    @Test func aThreadOpensWithOnePaneAtEverySize() {
        for (name, inputs) in sizes {
            for focused in PaneID.allCases {
                // A focused Overview is one the person chose; where it is the panel it opens beside Chat.
                if focused == .overview,
                    PaneLayoutRules.overviewIsPanel(
                        arrangement: ThreadLayout.arrangement(inputs, slotPaneCount: all.count), inputs: inputs)
                {
                    continue
                }
                let state = PaneState(focused: focused)
                let plan = PaneLayoutRules.plan(state, available: all, inputs: inputs)
                #expect(plan.arrangement == .single, "\(name) \(focused)")
                #expect(plan.slots.count == 1, "\(name) \(focused)")
                let size = ThreadLayout.arrangement(inputs, slotPaneCount: all.count)
                let resolved = PaneLayoutRules.arrangementChanged(
                    from: .single, to: size, state: state, available: all, inputs: inputs, oldInputs: outer)
                #expect(resolved.second == nil, "\(name) \(focused)")
                #expect(PaneLayoutRules.availableChanged(state: state, available: all, inputs: inputs).second == nil)
            }
        }
    }

    @Test func aChosenSecondPaneShowsAndIsKept() {
        let plan = PaneLayoutRules.plan(PaneState(), available: all, inputs: wide)
        let state = PaneLayoutRules.setSecond(.preview, state: PaneState(), plan: plan)
        #expect(state.focused == .chat && state.second == .preview)
        let two = PaneLayoutRules.plan(state, available: all, inputs: wide)
        #expect(two.shownPanes == [.chat, .preview])
        #expect(PaneLayoutRules.secondChoices(plan: two).current == .preview)
        let kept = PaneLayoutRules.availableChanged(state: state, available: all, inputs: wide)
        #expect(kept == state)
        #expect(PaneLayoutRules.setSecond(.preview, state: state, plan: two) == state)
    }

    @Test func aPickInAnotherSlotKeepsTheFocusedPane() {
        let plan = PaneLayoutRules.plan(PaneState(focused: .files), available: all, inputs: upright)
        let state = PaneLayoutRules.setSecond(.chat, state: PaneState(focused: .files), plan: plan)
        #expect(state.focused == .files && state.second == .chat)
        let two = PaneLayoutRules.plan(state, available: all, inputs: upright)
        let swapped = PaneLayoutRules.setSecond(.terminal, state: state, plan: two)
        #expect(swapped.focused == .files && swapped.second == .terminal)
    }

    @Test func noneClosesTheSecondPane() {
        let state = PaneState(focused: .chat, second: .preview)
        for inputs in [wide, upright, laptopInputs, bookInputs] {
            let plan = PaneLayoutRules.plan(state, available: all, inputs: inputs)
            let closed = PaneLayoutRules.setSecond(nil, state: state, plan: plan)
            #expect(closed.focused == .chat && closed.second == nil)
            #expect(PaneLayoutRules.plan(closed, available: all, inputs: inputs).slots.count == 1)
        }
    }

    @Test func noneClosesTheOverviewPanel() {
        let plan = PaneLayoutRules.plan(PaneState(focused: .files), available: all, inputs: wide)
        let open = PaneLayoutRules.setSecond(.overview, state: PaneState(focused: .files), plan: plan)
        let panelPlan = PaneLayoutRules.plan(open, available: all, inputs: wide)
        #expect(panelPlan.slots.contains { $0.content == .overviewPanel })
        #expect(PaneLayoutRules.secondChoices(plan: panelPlan).current == .overview)
        let closed = PaneLayoutRules.setSecond(nil, state: open, plan: panelPlan)
        #expect(closed.focused == .files && closed.second == nil && closed.behind == nil)
        let after = PaneLayoutRules.plan(closed, available: all, inputs: wide)
        #expect(after.slots == [PaneSlot(position: .single, content: .pane(.files))])
        // A focused Overview with a pane beside the panel closes to that pane.
        let focusedOverview = PaneState(focused: .overview, second: .preview)
        let beside = PaneLayoutRules.plan(focusedOverview, available: all, inputs: wide)
        let closedBeside = PaneLayoutRules.setSecond(nil, state: focusedOverview, plan: beside)
        #expect(closedBeside.focused == .preview && closedBeside.second == nil)
    }

    @Test func choosingAPaneWhileThePanelIsOpenReplacesThePanel() {
        let state = PaneState(focused: .chat, second: .overview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        let next = PaneLayoutRules.setSecond(.terminal, state: state, plan: plan)
        #expect(next.focused == .chat && next.second == .terminal && next.behind == nil)
    }

    @Test func choosingTheFocusedPaneChangesNothing() {
        for inputs in [wide, upright, laptopInputs] {
            let state = PaneState(focused: .files, second: .chat)
            let plan = PaneLayoutRules.plan(state, available: all, inputs: inputs)
            #expect(PaneLayoutRules.setSecond(.files, state: state, plan: plan) == state)
            let lone = PaneState(focused: .files)
            let single = PaneLayoutRules.plan(lone, available: all, inputs: inputs)
            #expect(PaneLayoutRules.setSecond(.files, state: lone, plan: single) == lone)
        }
        let panel = PaneState(focused: .chat, second: .overview)
        let panelPlan = PaneLayoutRules.plan(panel, available: all, inputs: wide)
        #expect(PaneLayoutRules.setSecond(.chat, state: panel, plan: panelPlan) == panel)
    }

    @Test func secondChoicesAreNotOfferedInOneSlotSizes() {
        let state = PaneState(focused: .chat, second: .preview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: outer)
        let choices = PaneLayoutRules.secondChoices(plan: plan)
        #expect(!choices.isOffered && choices.panes.isEmpty && choices.current == nil)
        #expect(PaneLayoutRules.setSecond(.files, state: state, plan: plan) == state)
        let onlyChat = PaneLayoutRules.plan(PaneState(), available: [.chat], inputs: wide)
        #expect(!PaneLayoutRules.secondChoices(plan: onlyChat).isOffered)
    }

    @Test func secondChoicesListTheOtherPanesInOrder() {
        let state = PaneState(focused: .files)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: upright)
        let choices = PaneLayoutRules.secondChoices(plan: plan)
        #expect(choices.isOffered && choices.current == nil)
        #expect(choices.panes == all.filter { $0 != .files })
    }

    @Test func aGoneSecondPaneLeavesOnePane() {
        let state = PaneState(focused: .chat, second: .preview)
        let next = PaneLayoutRules.availableChanged(
            state: state, available: [.chat, .files, .overview], inputs: wide)
        #expect(next.second == nil)
        #expect(PaneLayoutRules.plan(next, available: [.chat, .files, .overview], inputs: wide).slots.count == 1)
    }

    @Test func rotationKeepsTheChoice() {
        var state = PaneState(focused: .chat, second: .preview)
        state = PaneLayoutRules.arrangementChanged(
            from: .sideBySide, to: .single, state: state, available: all, inputs: outer, oldInputs: wide)
        #expect(state.second == .preview)
        #expect(PaneLayoutRules.plan(state, available: all, inputs: outer).slots.count == 1)
        state = PaneLayoutRules.arrangementChanged(
            from: .single, to: .stacked, state: state, available: all, inputs: upright, oldInputs: outer)
        #expect(PaneLayoutRules.plan(state, available: all, inputs: upright).shownPanes.count == 2)
        state = PaneLayoutRules.arrangementChanged(
            from: .stacked, to: .sideBySide, state: state, available: all, inputs: wide, oldInputs: upright)
        #expect(state.focused == .chat && state.second == .preview)
    }

    @Test func anOverviewFocusedAtAPanelSizeWithNothingBesideItOpensAsThePanelBesideChat() {
        let state = PaneLayoutRules.availableChanged(
            state: PaneState(focused: .overview), available: all, inputs: wide)
        #expect(state.focused == .chat && state.second == .overview)
        let plan = PaneLayoutRules.plan(state, available: all, inputs: wide)
        #expect(plan.slots.contains { $0.content == .overviewPanel })
    }

    @Test func anOlderStoredStateDropsItsSecondPane() {
        #expect(!PaneLayoutRules.keepsStoredSecondPane(formatVersion: nil))
        #expect(!PaneLayoutRules.keepsStoredSecondPane(formatVersion: 0))
        #expect(PaneLayoutRules.keepsStoredSecondPane(formatVersion: PaneLayoutRules.storedFormatVersion))
        #expect(PaneLayoutRules.keepsStoredSecondPane(formatVersion: PaneLayoutRules.storedFormatVersion + 1))
    }
}
