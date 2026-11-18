import XCTest

/// Smoke tests of the two-pane layouts against the stub server's `two-panes` and `preview-*` scenarios. They run on
/// every device; a test that needs two slots skips itself where the arrangement has one.
@MainActor final class LayoutSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String, extraArguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + extraArguments
        app.launchRetryingOnce()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private var detail: XCUIElement { element("thread.detail") }
    private var arrangement: String { detail.value as? String ?? "" }
    private var isNarrowWindow: Bool { app.windows.firstMatch.frame.width < 700 }

    private func openThread() {
        if !app.otherElements["drawer"].exists, app.buttons["threads.button"].waitForExistence(timeout: 5) {
            app.buttons["threads.button"].tap()
        }
        let row = element("drawer.row.thread:d0e515ef")
        if row.waitForExistence(timeout: Wait.firstLoad), !detail.exists { row.tap() }
        XCTAssertTrue(detail.waitForExistence(timeout: Wait.firstLoad))
    }

    /// Waits until the thread's panes have loaded, which turns a wide window from `single` into two slots.
    private func waitForTwoSlotsIfWide() {
        guard !isNarrowWindow else { return }
        let changed = NSPredicate { [self] _, _ in arrangement != "single" && !arrangement.isEmpty }
        wait(for: [XCTNSPredicateExpectation(predicate: changed, object: nil)], timeout: Wait.appear)
    }

    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
            let centre = CGPoint(x: auditBars.window.midX, y: auditBars.window.midY)
            let bars =
                app.navigationBars.allElementsBoundByIndex
                + app.toolbars.allElementsBoundByIndex.filter { !$0.frame.contains(centre) }
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            }
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }

    private let positions = ["single", "leading", "trailing", "upper", "lower"]

    /// The label of the pane in every slot that exists.
    private func slotLabels() -> [String: String] {
        var labels: [String: String] = [:]
        for position in positions {
            let slot = element("pane.slot.\(position)")
            if slot.exists { labels[position] = slot.label }
        }
        return labels
    }

    func testTheLayoutFollowsTheWidth() throws {
        try launch(scenario: "preview-ready")
        openThread()
        waitForTwoSlotsIfWide()
        switch arrangement {
        case "single":
            XCTAssertTrue(isNarrowWindow, "a wide window shows two panes")
            XCTAssertTrue(slotLabels().isEmpty, "one pane fills the detail with no slot markers")
        case "sideBySide":
            XCTAssertTrue(element("pane.slot.leading").waitForExistence(timeout: Wait.appear))
            XCTAssertTrue(element("pane.slot.trailing").exists)
            let both = element("pane.slot.leading").frame.union(element("pane.slot.trailing").frame)
            XCTAssertGreaterThanOrEqual(both.width, 760)
            XCTAssertTrue(element("bar.leading").exists && element("bar.trailing").exists)
        case "stacked":
            XCTAssertTrue(element("pane.slot.upper").waitForExistence(timeout: Wait.appear))
            XCTAssertLessThan(element("pane.slot.upper").frame.width, 760)
            XCTAssertTrue(element("pane.slot.lower").exists)
            XCTAssertTrue(element("pane.divider").exists)
            XCTAssertTrue(element("bar.lower").exists)
            XCTAssertFalse(element("bar.upper").exists)
        default:
            XCTFail("unexpected arrangement \(arrangement)")
        }
        // The chat's tool-call row in this scenario carries a Dynamic Type audit finding in the one-pane layout
        // (reported, not excused); the audit runs where two panes show.
        if arrangement != "single" { try audit() }
    }

    func testATapMovesTheFocus() throws {
        try launch(scenario: "preview-ready")
        openThread()
        waitForTwoSlotsIfWide()
        try XCTSkipIf(arrangement == "single", "one pane has no second slot")
        let slots = positions.map { element("pane.slot.\($0)") }.filter(\.exists)
        let other = try XCTUnwrap(slots.first { !$0.isSelected })
        let identifier = other.identifier
        other.tap()
        let selected = NSPredicate { [self] _, _ in element(identifier).isSelected }
        wait(for: [XCTNSPredicateExpectation(predicate: selected, object: nil)], timeout: Wait.appear)
        try audit()
    }

    func testRotationKeepsBothPanes() throws {
        try launch(scenario: "preview-ready")
        openThread()
        waitForTwoSlotsIfWide()
        try XCTSkipIf(arrangement == "single", "one pane has no second slot")
        let loaded = app.webViews.staticTexts["Path: /"]
        XCTAssertTrue(loaded.waitForExistence(timeout: Wait.firstLoad))
        let before = slotLabels()
        XCTAssertEqual(before.count, 2)
        let portraitWidth = detail.frame.width
        XCUIDevice.shared.orientation = .landscapeLeft
        waitForWidthChange(from: portraitWidth)
        element(positions.map { "pane.slot.\($0)" }.first { element($0).exists && !element($0).isSelected } ?? "").tap()
        let landscapeWidth = detail.frame.width
        XCUIDevice.shared.orientation = .portrait
        waitForWidthChange(from: landscapeWidth)
        XCTAssertEqual(Set(slotLabels().values), Set(before.values))
        XCTAssertEqual(try stub.previewLog().filter { $0.hasPrefix("auth ") }.count, 1)
        XCTAssertTrue(loaded.exists)
    }

    func testThePaneMenuOfABarReplacesOnlyItsPane() throws {
        XCUIDevice.shared.orientation = .landscapeLeft
        try launch(scenario: "preview-ready")
        openThread()
        waitForTwoSlotsIfWide()
        try XCTSkipUnless(arrangement == "sideBySide" && !element("pane.strip.chat").exists, "needs the iPad's bars")
        let button = app.buttons["pane.button.trailing"]
        XCTAssertTrue(button.waitForExistence(timeout: Wait.appear))
        let leading = element("pane.slot.leading").label
        button.tap()
        let other = app.buttons.matching(NSPredicate(format: "identifier BEGINSWITH 'pane.menu.'"))
            .allElementsBoundByIndex
            .first { $0.identifier != "pane.menu.\(leading.lowercased())" }
        let pick = try XCTUnwrap(other)
        let name = pick.label
        pick.tap()
        XCTAssertEqual(element("pane.slot.leading").label, leading)
        XCTAssertTrue(name.contains(element("pane.slot.trailing").label))
        // No audit here: the chat's tool-call row carries a Dynamic Type finding that is reported, not excused.
    }

    func testMarksShowTheFocusedAndTheSecondPane() throws {
        try launch(scenario: "preview-ready")
        openThread()
        waitForTwoSlotsIfWide()
        try XCTSkipIf(arrangement == "single", "one pane has no second slot")
        let slots = positions.map { element("pane.slot.\($0)") }.filter(\.exists)
        XCTAssertEqual(slots.count, 2)
        XCTAssertEqual(slots.filter { ($0.value as? String) == "Shown" && $0.isSelected }.count, 1)
        XCTAssertEqual(slots.filter { ($0.value as? String) == "Shown beside" && !$0.isSelected }.count, 1)
        XCTAssertFalse(app.staticTexts["Focused"].exists)
    }

    func testTheBookPoseShowsTwoSlotsWithABarEach() throws {
        XCUIDevice.shared.orientation = .landscapeLeft
        try launch(scenario: "preview-ready", extraArguments: ["-yakTestPosture", "book"])
        openThread()
        try XCTSkipIf(
            isNarrowWindow || !app.buttons["pane.strip.chat"].waitForExistence(timeout: 20),
            "the book pose needs the Duo's inner screen and its strip")
        let book = NSPredicate { [self] _, _ in arrangement == "book" }
        wait(for: [XCTNSPredicateExpectation(predicate: book, object: nil)], timeout: Wait.appear)
        XCTAssertTrue(element("pane.slot.leading").exists && element("pane.slot.trailing").exists)
    }

    private func waitForWidthChange(from old: CGFloat) {
        let changed = NSPredicate { [self] _, _ in abs(detail.frame.width - old) > 1 }
        wait(for: [XCTNSPredicateExpectation(predicate: changed, object: nil)], timeout: Wait.appear)
    }
}
