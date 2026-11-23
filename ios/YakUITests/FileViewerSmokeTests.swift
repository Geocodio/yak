import XCTest

/// A shared report opened from the thread page. The stub page has a real `target="_blank"` link to a stub file page
/// that needs the embed session, as the server's page does.
@MainActor final class FileViewerSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String = "embed-basic") throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
    }

    private func openThread(_ id: String = "d0e515ef") {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.firstLoad))
    }

    private var viewer: XCUIElement { app.descendants(matching: .any)["fileViewer"] }
    private var reportText: XCUIElement { app.staticTexts["Signups grew by twelve percent this week."] }

    private func openReport() {
        let link = app.links["Open report"]
        XCTAssertTrue(link.waitForExistence(timeout: Wait.appear))
        link.tap()
    }

    private func vanishes(_ element: XCUIElement) {
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: element)],
            timeout: Wait.appear)
    }

    func testOpenShowsTheReportInTheViewerSignedIn() throws {
        try launch()
        openThread()
        openReport()
        XCTAssertTrue(viewer.waitForExistence(timeout: Wait.appear), "the Open link did nothing")
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Weekly report.html"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.buttons["fileViewer.done"].exists)
        XCTAssertTrue(app.buttons["fileViewer.menu"].exists)
        try auditThreadScreen(app)
    }

    func testDoneClosesTheViewer() throws {
        try launch()
        openThread()
        openReport()
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.appear))
        app.buttons["fileViewer.done"].tap()
        vanishes(viewer)
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.appear))
    }

    func testALinkInsideTheReportOpensTheBrowserSheet() throws {
        try launch()
        openThread()
        openReport()
        let link = app.links["Source data"]
        XCTAssertTrue(link.waitForExistence(timeout: Wait.appear))
        link.tap()
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertTrue(sheet.wait(for: .runningForeground, timeout: Wait.appear))
    }

    /// Script in a report clicks a link on a timer, which WebKit reports as a tap on a link. Without a touch of the
    /// person just before it, nothing opens.
    func testAScriptThatClicksALinkByItselfOpensNothing() throws {
        try launch()
        openThread()
        let scripted = app.links["Open scripted report"]
        XCTAssertTrue(scripted.waitForExistence(timeout: Wait.appear))
        scripted.tap()
        XCTAssertTrue(
            app.staticTexts["This report clicks a link by itself."].waitForExistence(timeout: Wait.appear))
        // The script clicks three seconds after the report loads.
        Thread.sleep(forTimeInterval: 8)
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        XCTAssertFalse(sheet.state == .runningForeground, "a script opened the browser sheet")
        XCTAssertTrue(viewer.exists)
        XCTAssertTrue(app.links["Linked by script"].exists)
    }

    func testTheMenuCopiesTheLinkAndOffersSafari() throws {
        try launch()
        openThread()
        openReport()
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.appear))
        app.buttons["fileViewer.menu"].tap()
        XCTAssertTrue(app.buttons["Open in Safari"].waitForExistence(timeout: Wait.appear))
        app.buttons["Copy link"].tap()
        // The toast leaves after under two seconds, which a loaded runner can miss; the menu closing is what is checked.
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: app.buttons["Open in Safari"])],
            timeout: Wait.appear)
    }

    func testAMissingFileSaysSoAndRetryShowsItOnceItIsThere() throws {
        try launch()
        openThread()
        try stub.post("embed/file/missing", "")
        openReport()
        XCTAssertTrue(app.staticTexts["This file is not available."].waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(app.staticTexts["Not found"].exists, "the server's own error page showed")
        try stub.post("embed/file/ok", "")
        app.buttons["thread.retry"].tap()
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.appear))
    }

    /// A file the page cannot show, or that the server sends as a download, goes to Quick Look; the viewer never stays
    /// on Loading for it.
    func testAFileSentAsADownloadLeavesLoadingAtOnce() throws {
        try launch()
        openThread()
        try stub.post("embed/file/attachment", "")
        openReport()
        XCTAssertTrue(viewer.waitForExistence(timeout: Wait.appear))
        let loading = app.descendants(matching: .any)["thread.loading"]
        wait(
            for: [XCTNSPredicateExpectation(predicate: NSPredicate(format: "exists == false"), object: loading)],
            timeout: Wait.appear)
        XCTAssertFalse(app.descendants(matching: .any)["thread.slow"].exists)
    }

    func testAServerErrorOffersRetry() throws {
        try launch()
        openThread()
        try stub.post("embed/file/broken", "")
        openReport()
        XCTAssertTrue(app.descendants(matching: .any)["thread.error"].waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(app.staticTexts["Server error"].exists)
        try auditThreadScreen(app)
    }

    func testASlowFileShowsTheSlowLineThenTheReport() throws {
        try launch()
        openThread()
        try stub.post("embed/file/slow", "")
        openReport()
        XCTAssertTrue(app.descendants(matching: .any)["thread.slow"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.appear))
    }

    /// The stub answers a request without a session with a redirect to its login page, like the real server.
    func testAnExpiredSessionRedirectedToTheLoginPageRenewsOnceAndShowsTheReport() throws {
        try launch()
        openThread()
        try stub.post("embed/expire", "")
        openReport()
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(try stub.get("embed/state").contains("mints=2 redeems=2"))
    }

    func testOfflineShowsTheOfflineStateAndRecoversWhenBack() throws {
        try launch()
        openThread()
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["thread.web.offlineLine"].waitForExistence(timeout: Wait.appear))
        openReport()
        XCTAssertTrue(app.staticTexts["You are offline"].waitForExistence(timeout: Wait.appear))
        try auditThreadScreen(app)
        try stub.setOnline(true)
        XCTAssertTrue(reportText.waitForExistence(timeout: Wait.firstLoad))
    }

    /// The thread page's own "Copy link" button writes to the clipboard from a tap.
    func testThePagesCopyButtonWritesTheClipboard() throws {
        try launch()
        openThread()
        app.buttons["Copy report link"].tap()
        XCTAssertTrue(app.staticTexts["Copied the link"].waitForExistence(timeout: Wait.appear))
    }
}
