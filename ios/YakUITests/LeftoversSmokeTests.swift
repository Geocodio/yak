import XCTest

/// Leftover screens against the stub server.
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
}
