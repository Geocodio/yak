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

    /// The gallery draws each state's texts: a count and the first title, the empty and error sentences, and a private
    /// thread without its title. The accessibility audit of the gallery runs in the walkthrough, where findings are
    /// reported and do not block.
    func testGalleryShowsEachState() throws {
        let expectations: [(state: String, present: [String])] = [
            ("needs-you", ["Needs you", "Retry failed rows when the import times out", "Asked you a question"]),
            ("busy", ["Running the test suite", "Task: Review the import job"]),
            ("empty", ["Nothing needs you.", "Nothing is running."]),
            ("private-first", ["A private thread"]),
            ("no-instance", ["Pair Yak to see what needs you."]),
            ("needs-pairing", ["Pair yak.example.com again in Yak."]),
            ("not-loaded", ["Open Yak to load your threads."]),
        ]
        for expectation in expectations {
            app.terminate()
            try openGallery(expectation.state)
            for text in expectation.present {
                let match = app.descendants(matching: .any).matching(
                    NSPredicate(format: "label CONTAINS %@", text)
                ).firstMatch
                XCTAssertTrue(match.waitForExistence(timeout: 10), "\(expectation.state) shows \(text)")
            }
        }
    }

    func testStartThreadIntentFromTestMode() throws {
        try stub.play("paired")
        app.launchArguments = [
            "-yakTestInstance", stub.url, "-yakTestToken", "stub-token", "-yakTestIntent", "start-thread",
        ]
        app.launch()
        let result = app.staticTexts["intent.result"]
        XCTAssertTrue(result.waitForExistence(timeout: 30))
        let started = NSPredicate(format: "label BEGINSWITH 'Started a thread in '")
        expectation(for: started, evaluatedWith: result)
        waitForExpectations(timeout: 30)
    }

    func testNewThreadQuickActionOpensTheNewThreadScreen() throws {
        try stub.play("paired")
        app.launchArguments = [
            "-yakTestInstance", stub.url, "-yakTestToken", "stub-token", "-yakTestQuickAction", "new-thread",
        ]
        app.launch()
        XCTAssertTrue(app.descendants(matching: .any)["newThread.repo"].waitForExistence(timeout: 30))
    }
}
