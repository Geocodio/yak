import XCTest

/// The quality-bar walkthrough of the widgets: one screenshot per gallery state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:WidgetsWalkthroughTests`.
@MainActor final class WidgetsWalkthroughTests: WalkthroughCase {
    static let galleryStates = [
        "needs-you", "busy", "empty", "private-first", "long-title", "huge-count", "stale", "no-instance",
        "instance-missing", "needs-pairing", "not-loaded", "placeholder",
    ]

    /// The debug gallery, one launch per state.
    func testWidgetGallery() throws {
        for state in Self.galleryStates {
            app.terminate()
            app.launchEnvironment["YAK_TEST_GALLERY_STATE"] = state
            try start("paired", extraArguments: ["-yakTestScreen", "widget-gallery"])
            XCTAssertTrue(app.navigationBars[state].waitForExistence(timeout: 10), state)
            try shoot("widgets-\(state)")
        }
    }
}
