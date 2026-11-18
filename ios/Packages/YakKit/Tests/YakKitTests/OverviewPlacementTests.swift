import Testing

@testable import YakKit

@Suite struct OverviewPlacementTests {
    @Test func compactWidthIsAPane() {
        #expect(OverviewPlacement.choose(isCompactWidth: true, availableWidth: 1200) == .pane)
    }

    @Test func tooNarrowIsAPane() {
        #expect(OverviewPlacement.choose(isCompactWidth: false, availableWidth: 700) == .pane)
    }

    @Test func theNarrowestPanelIs320Wide() {
        #expect(OverviewPlacement.choose(isCompactWidth: false, availableWidth: 760) == .sidePanel(width: 320))
    }

    @Test func thePanelStopsAt400() {
        #expect(OverviewPlacement.choose(isCompactWidth: false, availableWidth: 1194) == .sidePanel(width: 400))
    }

    @Test func inBetweenFollowsTheWidth() throws {
        guard case .sidePanel(let width) = OverviewPlacement.choose(isCompactWidth: false, availableWidth: 951) else {
            Issue.record("a side panel")
            return
        }
        #expect(abs(width - 361.38) < 0.01)
    }
}
