import XCTest

/// Every state of the drawer's search, shot and audited: idle, one character, loading, results, no results, offline,
/// a failed search, a limited one and a pull request opened in the browser sheet. The browser sheet is shot without
/// the audit, which cannot reach it.
@MainActor final class SearchWalkthroughTests: WalkthroughCase {
    private let thread = "d0e515ef"
    private var isLargestText: Bool { environment["YAK_TEXT_SIZE"] == "largest" }
    /// At the largest text a state that keeps the loaded matches under its message cannot show them above the bottom
    /// bar, so it is shot with a text that matches none of the loaded threads: every line is then whole on screen.
    private var fallbackQuery: String { isLargestText ? "worker" : "retry" }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }
    private func row(_ id: String) -> XCUIElement { element("drawer.row.thread:\(id)") }

    private func launch(_ scenario: String = "search") throws {
        try start(scenario, paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(row(thread).waitForExistence(timeout: Wait.firstLoad))
    }

    private func type(_ text: String) {
        let field = app.textFields["drawer.search"]
        field.tap()
        field.typeText(text)
    }

    /// The keyboard closes before the shot, so the screen is the drawer and not the keyboard over it.
    private func submit() {
        app.textFields["drawer.search"].typeText("\n")
        wait(
            for: [
                XCTNSPredicateExpectation(
                    predicate: NSPredicate(format: "exists == false"), object: app.keyboards.firstMatch)
            ],
            timeout: Wait.appear)
    }

    func testIdle() throws {
        try launch()
        try shoot("search-idle", audits: false)
    }

    func testOneCharacter() throws {
        try launch()
        type("w")
        XCTAssertTrue(element("search.hint").waitForExistence(timeout: Wait.appear))
        try shoot("search-one-character", audits: false)
    }

    /// One character that matches nothing in the tab: the message and the way to widen to every tab.
    func testOneCharacterNoMatch() throws {
        try launch()
        type("z")
        XCTAssertTrue(app.buttons["drawer.noResults.widen"].waitForExistence(timeout: Wait.appear))
        try shoot("search-one-character-no-match")
    }

    func testLoading() throws {
        try launch("search-slow")
        type("retry")
        XCTAssertTrue(element("search.loading").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-loading", audits: false)
        try stub.releaseSearch()
    }

    /// At the largest text the results are one thread, so every row is whole on screen above the bottom bar.
    func testResults() throws {
        try launch(isLargestText ? "search-one" : "search")
        type("retry")
        XCTAssertTrue(element("search.results").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-results")
    }

    func testNoResults() throws {
        try launch("search-empty")
        type("retryx")
        XCTAssertTrue(element("search.empty").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-no-results")
    }

    func testOffline() throws {
        try launch()
        try stub.setOnline(false)
        XCTAssertTrue(element("drawer.offlineNotice").waitForExistence(timeout: Wait.appear))
        type(fallbackQuery)
        XCTAssertTrue(element("search.offline").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-offline")
    }

    func testError() throws {
        try launch("search-failing")
        type(fallbackQuery)
        XCTAssertTrue(element("search.failed").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-error")
    }

    func testRateLimited() throws {
        try launch("search-limited-long")
        type(fallbackQuery)
        XCTAssertTrue(element("search.limited").waitForExistence(timeout: Wait.appear))
        submit()
        try shoot("search-rate-limited")
    }

    func testPullRequestInTheBrowserSheet() throws {
        try launch()
        type("worker")
        XCTAssertTrue(element("search.results").waitForExistence(timeout: Wait.appear))
        submit()
        element("search.result.pullRequest:https://github.com/example/retry-worker/pull/88").tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
        try shoot("search-browser-sheet", audits: false)
    }
}
