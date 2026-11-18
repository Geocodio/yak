import XCTest

/// The walkthrough player against the stub's sample video.
@MainActor final class LeftoversSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    /// The failing card's "Edit .yak/preview.yml" opens the manifest in the Files editor.
    func testEditManifestFromTheFailingCard() throws {
        try stub.play("preview-failing-files")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        PreviewScreen(app: app).open()
        XCTAssertTrue(element("preview.card.failing").waitForExistence(timeout: Wait.appear))
        let edit = app.buttons["preview.editManifest"]
        XCTAssertTrue(edit.waitForExistence(timeout: Wait.appear))
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                    // The system caps the size of text in its bars.
                    if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                }
                return AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars)
                    || AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars)
            }
        }
        edit.tap()
        let editor = app.textViews["files.editor"]
        XCTAssertTrue(editor.waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue((editor.value as? String ?? "").contains("cold_start: npm run dev"))
    }

    func testWalkthroughPlaysAndCloses() throws {
        try stub.play("task-walkthrough")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.open(stub.taskLink)
        let row = element("task.walkthrough")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(element("walkthrough.player").waitForExistence(timeout: Wait.appear))
        let failure = NSPredicate(format: "identifier BEGINSWITH 'walkthrough.failed.'")
        XCTAssertFalse(app.descendants(matching: .any).matching(failure).firstMatch.waitForExistence(timeout: 10))
        element("walkthrough.close").tap()
        XCTAssertTrue(element("task.screen").waitForExistence(timeout: 10))
        let log = try stub.walkthroughVideoLog()
        XCTAssertTrue(log.contains { $0.path == "/__stub/walkthrough-video/sample.mp4" && $0.hasRange })
        XCTAssertFalse(log.contains { $0.hasAuthorization })
    }
}
