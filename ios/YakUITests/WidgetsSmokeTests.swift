import XCTest

/// The widget gallery against the accessibility audit. The Home Screen itself is not driven; what a widget shows is
/// proven by the unit tests of the content and by the gallery.
@MainActor final class WidgetsSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func openGallery(_ state: String) throws {
        try stub.play("paired")
        app.launchEnvironment["YAK_TEST_GALLERY_STATE"] = state
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestScreen", "widget-gallery"]
        app.launch()
        XCTAssertTrue(app.navigationBars[state].waitForExistence(timeout: 30))
    }

    func testGalleryPassesTheAccessibilityAudit() throws {
        for state in ["needs-you", "busy"] {
            app.terminate()
            try openGallery(state)
            try app.performAccessibilityAudit { [self] issue in
                let screenshot = XCTAttachment(screenshot: app.screenshot())
                screenshot.lifetime = .keepAlways
                screenshot.name = "audit-failure-\(state)"
                add(screenshot)
                XCTFail(
                    "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
                )
                return true
            }
        }
    }
}
