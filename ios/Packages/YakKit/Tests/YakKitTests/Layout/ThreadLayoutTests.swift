import Testing

@testable import YakKit

struct ArrangementCase: Sendable {
    var isCompactWidth: Bool
    var isCompactHeight: Bool
    var width: Double
    var edge: Bool
    var posture: HingePosture
    var fold: FoldAxis?
    var panes: Int
    var expected: PaneArrangement

    init(
        _ w: Bool, _ h: Bool, _ width: Double, _ edge: Bool, _ posture: HingePosture, _ fold: FoldAxis?,
        _ panes: Int, _ expected: PaneArrangement
    ) {
        isCompactWidth = w
        isCompactHeight = h
        self.width = width
        self.edge = edge
        self.posture = posture
        self.fold = fold
        self.panes = panes
        self.expected = expected
    }

    var inputs: LayoutInputs {
        LayoutInputs(
            isCompactWidth: isCompactWidth, isCompactHeight: isCompactHeight, detailWidth: width,
            hasVerticalToolbarEdge: edge, posture: posture, fold: fold)
    }
}

@Suite struct ThreadLayoutTests {
    @Test(arguments: [
        ArrangementCase(true, false, 466, true, .flat, nil, 5, .single),
        ArrangementCase(true, true, 466, true, .flat, nil, 5, .single),
        ArrangementCase(false, false, 867, true, .flat, nil, 5, .sideBySide),
        ArrangementCase(false, false, 669, false, .flat, nil, 5, .stacked),
        ArrangementCase(false, false, 874, false, .flat, nil, 5, .sideBySide),
        ArrangementCase(false, false, 514, false, .flat, nil, 5, .stacked),
        ArrangementCase(true, false, 475, false, .flat, nil, 5, .single),
        ArrangementCase(true, true, 844, false, .flat, nil, 5, .single),
        ArrangementCase(false, true, 932, false, .flat, nil, 5, .single),
        ArrangementCase(false, false, 760, false, .flat, nil, 2, .sideBySide),
        ArrangementCase(false, false, 759.5, false, .flat, nil, 2, .stacked),
        ArrangementCase(false, false, 1200, false, .flat, nil, 1, .single),
        ArrangementCase(false, false, 867, true, .flat, nil, 2, .sideBySide),
        ArrangementCase(false, true, 932, false, .flat, nil, 2, .single),
        ArrangementCase(false, true, 932, true, .flat, nil, 2, .sideBySide),
        ArrangementCase(false, false, 867, true, .partlyOpen, .vertical, 2, .book),
        ArrangementCase(false, false, 669, false, .partlyOpen, .horizontal, 2, .laptop),
        ArrangementCase(false, false, 669, false, .partlyOpen, nil, 2, .stacked),
        ArrangementCase(true, false, 475, false, .partlyOpen, .horizontal, 2, .single),
        ArrangementCase(false, false, 951, false, .closed, nil, 2, .sideBySide),
        ArrangementCase(false, false, 867, true, .partlyOpen, .vertical, 1, .single),
    ])
    func arrangementFollowsTheRules(_ row: ArrangementCase) {
        #expect(ThreadLayout.arrangement(row.inputs, slotPaneCount: row.panes) == row.expected)
    }

    @Test(arguments: [0, -3])
    func fewerThanTwoPanesIsSingle(count: Int) {
        let inputs = LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: 1200, hasVerticalToolbarEdge: false)
        #expect(ThreadLayout.arrangement(inputs, slotPaneCount: count) == .single)
    }

    @Test(arguments: [Double.nan, .infinity, -.infinity, -1])
    func aWidthThatIsNotARealNumberGivesOnePane(width: Double) {
        let inputs = LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: width, hasVerticalToolbarEdge: false)
        #expect(ThreadLayout.arrangement(inputs, slotPaneCount: 2) == .single)
    }

    @Test func theKeyboardInTheLaptopPoseKeepsTheLaptop() {
        func make(
            compactWidth: Bool = false, compactHeight: Bool = false, posture: HingePosture = .partlyOpen,
            fold: FoldAxis? = nil, keyboard: Bool = false
        ) -> LayoutInputs {
            LayoutInputs(
                isCompactWidth: compactWidth, isCompactHeight: compactHeight, detailWidth: 669,
                hasVerticalToolbarEdge: false, posture: posture, fold: fold, isKeyboardOnLowerHalf: keyboard)
        }
        #expect(ThreadLayout.arrangement(make(fold: .horizontal), slotPaneCount: 2) == .laptop)
        #expect(ThreadLayout.arrangement(make(compactHeight: true, keyboard: true), slotPaneCount: 2) == .laptop)
        #expect(ThreadLayout.arrangement(make(fold: .horizontal), slotPaneCount: 2) == .laptop)
        #expect(ThreadLayout.arrangement(make(compactWidth: true, keyboard: true), slotPaneCount: 2) == .single)
        #expect(ThreadLayout.arrangement(make(posture: .flat, keyboard: true), slotPaneCount: 2) == .stacked)
        #expect(ThreadLayout.arrangement(make(compactHeight: true, keyboard: true), slotPaneCount: 1) == .single)
    }

    @Test func foldAxisComesFromTheRegionShape() {
        #expect(FoldAxis(regionWidth: 40, regionHeight: 669) == .vertical)
        #expect(FoldAxis(regionWidth: 669, regionHeight: 40) == .horizontal)
        #expect(FoldAxis(regionWidth: 0, regionHeight: 669) == nil)
        #expect(FoldAxis(regionWidth: 40, regionHeight: -1) == nil)
        #expect(FoldAxis(regionWidth: .nan, regionHeight: 40) == nil)
        #expect(FoldAxis(regionWidth: 40, regionHeight: .infinity) == nil)
        #expect(FoldAxis(regionWidth: 40, regionHeight: 40) == nil)
    }

    @Test func unknownHingeStatesAreFlat() {
        #expect(HingePosture(systemState: "fullyOpen") == .flat)
        #expect(HingePosture(systemState: "partiallyOpen") == .partlyOpen)
        #expect(HingePosture(systemState: "closed") == .closed)
        #expect(HingePosture(systemState: "folding") == .flat)
        #expect(HingePosture(systemState: "") == .flat)
    }

    @Test func slotCountsAndHandles() {
        for arrangement in [PaneArrangement.single, .sideBySide, .stacked, .book, .laptop] {
            #expect(arrangement.slotCount == (arrangement == .single ? 1 : 2))
            #expect(arrangement.hasDragHandle == (arrangement == .stacked))
            #expect(arrangement.barsPerSlot == (arrangement == .sideBySide || arrangement == .book))
        }
    }
}
