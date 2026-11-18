import XCTest

/// Smoke test of the merge order of a thread's pull requests, against the stub server.
@MainActor final class PullRequestOrderSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openCard() throws {
        try stub.play("pull-request-open")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = element("drawer.row.thread:d0e515ef")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        row.tap()
        let menu = app.buttons["pane.menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: Wait.appear))
        menu.tap()
        app.buttons.matching(NSPredicate(format: "label == %@", "Files")).firstMatch.tap()
        let button = element("files.openPullRequest")
        XCTAssertTrue(button.waitForExistence(timeout: Wait.firstLoad))
        button.tap()
        XCTAssertTrue(element("pullRequest.row.78").waitForExistence(timeout: Wait.firstLoad))
    }

    /// Dragging a row's handle saves the new order: the stub sees it, the rows are renumbered, and Done leaves the
    /// edit mode. The screenshot of the edit mode is saved to `ios/build/screenshots/pr-order`.
    func testReorderingTwoPullRequestsSavesTheNewOrder() throws {
        try openCard()
        let edit = element("pullRequest.order.edit")
        XCTAssertTrue(edit.waitForExistence(timeout: Wait.appear))
        edit.tap()
        let first = element("pullRequest.order.row.77")
        let second = element("pullRequest.order.row.78")
        XCTAssertTrue(first.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(second.exists)
        XCTAssertLessThan(first.frame.minY, second.frame.minY)
        try saveScreenshot("edit-mode")
        // The reorder handle is at the trailing edge of the row.
        let start = first.coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 0.5))
        let end = second.coordinate(withNormalizedOffset: CGVector(dx: 0.93, dy: 1.4))
        start.press(forDuration: 0.6, thenDragTo: end)
        let saved = NSPredicate { [stub] _, _ in
            ((try? stub?.lastPullRequestOrder()) ?? "").contains("[78,77]")
        }
        wait(for: [XCTNSPredicateExpectation(predicate: saved, object: nil)], timeout: Wait.appear)
        XCTAssertLessThan(
            element("pullRequest.order.row.78").frame.minY, element("pullRequest.order.row.77").frame.minY)
        try audit()
        app.navigationBars.buttons["Done"].tap()
        XCTAssertTrue(element("pullRequest.row.78").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("pullRequest.order.list").exists)
        XCTAssertLessThan(element("pullRequest.row.78").frame.minY, element("pullRequest.row.77").frame.minY)
        try audit()
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                // The system caps the size of text in its bars.
                let bars = app.navigationBars.allElementsBoundByIndex
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }),
                    [.dynamicType, .textClipped].contains(issue.auditType)
                {
                    return true
                }
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }

    private func saveScreenshot(_ name: String) throws {
        Thread.sleep(forTimeInterval: 0.8)
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        directory.appendPathComponent("build/screenshots/pr-order")
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let file = directory.appendingPathComponent("\(name).png")
        try XCUIScreen.main.screenshot().pngRepresentation.write(to: file)
        print("screenshot: \(file.path)")
    }
}
