import XCTest

/// Smoke tests of the drawer's search against the stub server's `search` scenarios: one character narrows the loaded
/// threads, two ask the server and show threads, repositories and pull requests in groups, and a failure, a limit
/// or a lost connection says what happened and keeps the loaded threads under it.
@MainActor final class SearchSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()
    private let thread = "d0e515ef"
    private let otherThread = "a1b2c3d4"
    private let repository = "search.result.repository:example/retry-worker"
    private let pullRequestWithoutThread = "search.result.pullRequest:https://github.com/example/retry-worker/pull/88"
    private let pullRequestWithThread = "search.result.pullRequest:https://github.com/example/web/pull/2301"

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }
    private func row(_ id: String) -> XCUIElement { element("drawer.row.thread:\(id)") }
    private var search: XCUIElement { app.textFields["drawer.search"] }

    private func launch(_ scenario: String = "search") throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(row(thread).waitForExistence(timeout: Wait.firstLoad))
    }

    private func type(_ text: String) {
        search.tap()
        search.typeText(text)
    }

    private func waitUntilGone(_ element: XCUIElement) {
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: element)],
            timeout: Wait.appear)
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }

    private func waitForResults() {
        XCTAssertTrue(element("search.results").waitForExistence(timeout: Wait.appear))
    }

    // MARK: One character

    /// One character asks the server nothing: a hint appears and the loaded threads narrow to the matches. Clearing
    /// the field brings the whole list back at once.
    func testOneCharacterNarrowsTheLoadedThreadsAndClearingBringsThemBack() throws {
        try launch()
        XCTAssertTrue(row(otherThread).exists)
        type("w")
        XCTAssertTrue(element("search.hint").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(row(thread).exists)
        waitUntilGone(row(otherThread))
        XCTAssertEqual(stub.searchLog(), [])
        app.buttons["drawer.clearSearch"].tap()
        XCTAssertTrue(row(otherThread).waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("search.hint").exists)
    }

    // MARK: Results

    /// Two characters show shapes while the server thinks, then the three groups. The search key dismisses the keyboard
    /// and keeps the results. The screen passes the audit with the shapes and with the results.
    func testTwoCharactersShowLoadingThenTheGroups() throws {
        try launch("search-slow")
        type("retry")
        XCTAssertTrue(element("search.loading").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(row(otherThread).exists)
        try stub.releaseSearch()
        waitForResults()
        waitUntilGone(element("search.loading"))
        for group in ["thread", "repository", "pullRequest"] {
            XCTAssertTrue(element("search.group.\(group)").exists, "no \(group) group")
        }
        XCTAssertTrue(row(thread).exists)
        XCTAssertTrue(element(repository).exists)
        XCTAssertTrue(element(pullRequestWithThread).exists)
        XCTAssertTrue(element(pullRequestWithoutThread).exists)
        XCTAssertEqual(stub.searchLog(), ["retry"])
        search.typeText("\n")
        XCTAssertFalse(app.keyboards.firstMatch.waitForExistence(timeout: 2))
        XCTAssertTrue(element("search.results").exists)
        XCTAssertEqual(search.value as? String, "retry")
        try audit()
    }

    func testNoResultsSaysWhatWasSearched() throws {
        try launch("search-empty")
        type("zzzz\n")
        XCTAssertTrue(element("search.empty").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("search.empty").label.contains("zzzz"))
        XCTAssertFalse(element("search.results").exists)
        try audit()
    }

    /// Clearing the field returns to the list at once, also while the results are showing.
    func testClearingTheFieldReturnsToTheList() throws {
        try launch()
        type("retry")
        waitForResults()
        app.buttons["drawer.clearSearch"].tap()
        XCTAssertTrue(row(otherThread).waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("search.results").exists)
    }

    /// Server search covers everything whatever the tab, so no tab row or filter shows while it does (loading and
    /// results alike). Below two characters and after clearing, the tabs are back.
    func testTheTabsGiveWayToServerSearchAndComeBack() throws {
        try launch("search-slow")
        XCTAssertTrue(element("drawer.tabs").exists && element("drawer.filter").exists)
        type("retry")
        XCTAssertTrue(element("search.loading").waitForExistence(timeout: Wait.appear))
        waitUntilGone(element("drawer.tabs"))
        XCTAssertFalse(element("drawer.filter").exists)
        try stub.releaseSearch()
        waitForResults()
        XCTAssertFalse(element("drawer.tabs").exists || element("drawer.filter").exists)
        app.buttons["drawer.clearSearch"].tap()
        XCTAssertTrue(element("drawer.tabs").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.filter").exists)
        search.typeText("w")
        XCTAssertTrue(element("search.hint").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.tabs").exists)
    }

    // MARK: Opening a result

    func testAThreadResultOpensTheThread() throws {
        try launch()
        type("retry\n")
        waitForResults()
        row(thread).tap()
        XCTAssertTrue(app.buttons["thread.menu"].waitForExistence(timeout: Wait.appear))
    }

    func testAPullRequestWithAThreadOpensTheThread() throws {
        try launch()
        type("retry\n")
        waitForResults()
        element(pullRequestWithThread).tap()
        XCTAssertTrue(app.buttons["thread.menu"].waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(XCUIApplication(bundleIdentifier: "com.apple.SafariViewService").state == .runningForeground)
    }

    func testAPullRequestWithoutAThreadOpensTheBrowserSheet() throws {
        try launch()
        type("retry\n")
        waitForResults()
        element(pullRequestWithoutThread).tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
    }

    func testARepositoryOpensTheBrowserSheet() throws {
        try launch()
        type("retry\n")
        waitForResults()
        element(repository).tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
    }

    // MARK: Failures

    /// A failed search says so, keeps the loaded matches under "Loaded threads" and asks again when told to.
    func testAFailedSearchOffersTryAgain() throws {
        try launch("search-failing")
        type("retry\n")
        XCTAssertTrue(element("search.failed").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Loaded threads"].exists)
        XCTAssertTrue(row(thread).exists)
        try audit()
        try stub.relentSearch()
        element("search.retry").tap()
        waitForResults()
        XCTAssertFalse(element("search.failed").exists)
    }

    /// A limited search says to wait and asks again by itself once the wait has passed.
    func testALimitedSearchRetriesByItself() throws {
        try launch("search-limited")
        type("retry\n")
        XCTAssertTrue(element("search.limited").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Loaded threads"].exists)
        try audit()
        try stub.relentSearch()
        waitForResults()
        XCTAssertFalse(element("search.limited").exists)
    }

    /// Offline the field narrows the threads saved on the phone under a heading that says so, and the server is asked
    /// again when the connection returns.
    func testOfflineShowsTheLocalFilterUnderItsHeading() throws {
        try launch()
        try stub.setOnline(false)
        XCTAssertTrue(element("drawer.offlineNotice").waitForExistence(timeout: Wait.appear))
        type("retry\n")
        XCTAssertTrue(element("search.offline").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Saved on this iPhone"].exists)
        XCTAssertTrue(row(thread).exists)
        waitUntilGone(row(otherThread))
        try audit()
        try stub.setOnline(true)
        waitForResults()
    }
}
