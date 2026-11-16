import XCTest

/// The quality-bar walkthrough of the Files pane in Changed mode, the Open PR button and the pull request card: one
/// screenshot per state in `$YAK_SHOT_DIR`. Run it with `bundle exec fastlane walkthrough only:ChangesWalkthroughTests`.
@MainActor final class ChangesWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openThread() throws {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = element("drawer.row.thread:d0e515ef")
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        row.tap()
    }

    /// The pane menu on a phone, the pane buttons where the bar holds them.
    private func showFiles() {
        let menu = app.buttons["pane.menu"]
        if menu.waitForExistence(timeout: 15) {
            menu.tap()
            let item = app.buttons.matching(NSPredicate(format: "label == %@", "Files")).firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 5))
            item.tap()
        } else {
            let item = app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", "Files")).firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 10))
            item.tap()
        }
        XCTAssertTrue(element("files.bar").waitForExistence(timeout: 15))
    }

    private func openFiles(_ scenario: String) throws {
        try start(scenario, paired: true)
        try openThread()
        showFiles()
    }

    private func goToFile(_ position: Int) {
        for _ in 1..<position {
            let next = element("files.next")
            XCTAssertTrue(next.isHittable || next.waitForExistence(timeout: 5))
            next.tap()
            Thread.sleep(forTimeInterval: 0.4)
        }
    }

    // MARK: Files

    func testFilesChanged() throws {
        try openFiles("changes")
        XCTAssertTrue(label("retry(3)").waitForExistence(timeout: 15))
        try shoot("files-changed")
    }

    func testFilesBinary() throws {
        try openFiles("changes")
        goToFile(2)
        XCTAssertTrue(element("files.page.binary").waitForExistence(timeout: 10))
        try shoot("files-binary")
    }

    func testFilesRenamed() throws {
        try openFiles("changes")
        goToFile(3)
        XCTAssertTrue(label("Renamed from").waitForExistence(timeout: 10))
        try shoot("files-renamed")
    }

    func testFilesTooLarge() throws {
        try openFiles("changes")
        goToFile(4)
        XCTAssertTrue(element("files.page.tooLarge").waitForExistence(timeout: 10))
        try shoot("files-too-large")
    }

    func testFilesEmpty() throws {
        try openFiles("changes-none")
        XCTAssertTrue(element("files.empty").waitForExistence(timeout: 15))
        try shoot("files-empty")
    }

    func testFilesLoading() throws {
        try openFiles("changes-slow")
        Thread.sleep(forTimeInterval: 1.5)
        try shoot("files-loading")
        XCTAssertTrue(element("files.slow").waitForExistence(timeout: 14))
        try shoot("files-slow")
    }

    func testFilesOffline() throws {
        try openFiles("changes")
        XCTAssertTrue(label("retry(3)").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        app.terminate()
        app.launch()
        try openThread()
        showFiles()
        XCTAssertTrue(element("files.offlineNotice").waitForExistence(timeout: 30))
        try shoot("files-offline")
    }

    func testFilesNotSaved() throws {
        try openFiles("changes")
        XCTAssertTrue(label("retry(3)").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        goToFile(3)
        XCTAssertTrue(element("files.page.notSaved").waitForExistence(timeout: 30))
        try shoot("files-not-saved")
    }

    func testFilesWorking() throws {
        try openFiles("changes-working")
        XCTAssertTrue(label("Yak is still working").waitForExistence(timeout: 15))
        try shoot("files-working")
        let ended = NSPredicate(format: "NOT (label CONTAINS %@)", "Yak is still working")
        let banner = element("files.banner")
        wait(for: [XCTNSPredicateExpectation(predicate: ended, object: banner)], timeout: 20)
        try shoot("files-updated")
    }

    func testFilesGone() throws {
        try openFiles("changes-gone")
        goToFile(3)
        XCTAssertTrue(label("no longer in the changes").waitForExistence(timeout: 15))
        try shoot("files-gone")
    }

    func testFilesDuo() throws {
        try openFiles("changes")
        XCTAssertTrue(label("retry(3)").waitForExistence(timeout: 15))
        try shoot("files-duo")
    }

    func testFilesList() throws {
        try openFiles("changes")
        XCTAssertTrue(element("files.list").waitForExistence(timeout: 15))
        element("files.list").tap()
        XCTAssertTrue(element("files.list.sheet").waitForExistence(timeout: 10))
        try shoot("files-list")
    }

    // MARK: Open PR

    func testOpenPullRequestPrivate() throws {
        try openFiles("changes-private")
        element("files.openPullRequest").tap()
        XCTAssertTrue(element("files.private").waitForExistence(timeout: 10))
        try shoot("open-pr-private")
    }

    func testOpenPullRequestOpening() throws {
        try openFiles("changes")
        element("files.openPullRequest").tap()
        XCTAssertTrue(label("Opening PR").waitForExistence(timeout: 10))
        try shoot("open-pr-opening")
    }

    func testOpenPullRequestOpen() throws {
        try openFiles("pull-request-open")
        XCTAssertTrue(label("Open").waitForExistence(timeout: 15))
        try shoot("open-pr-open")
    }

    func testOpenPullRequestHeld() throws {
        try openFiles("pull-request-held")
        element("files.openPullRequest").tap()
        XCTAssertTrue(label("Waiting for a co-owner").waitForExistence(timeout: 15))
        try shoot("open-pr-held")
    }

    func testOpenPullRequestFailed() throws {
        try openFiles("pull-request-failed")
        XCTAssertTrue(label("GitHub refused the branch").waitForExistence(timeout: 15))
        try shoot("open-pr-failed")
    }

    func testOpenPullRequestRefused() throws {
        try openFiles("pull-request-refused")
        element("files.openPullRequest").tap()
        XCTAssertTrue(element("files.open.notice").waitForExistence(timeout: 10))
        try shoot("open-pr-refused")
    }

    func testOpenPullRequestDisabled() throws {
        try openFiles("changes-none")
        XCTAssertTrue(element("files.openPullRequest").waitForExistence(timeout: 10))
        try shoot("open-pr-disabled")
    }

    func testOpenPullRequestReadOnly() throws {
        try start("thread-states", paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        components.queryItems = [
            URLQueryItem(name: "instance", value: stub.url), URLQueryItem(name: "id", value: "readc0py"),
        ]
        app.open(try XCTUnwrap(components.url))
        XCTAssertTrue(element("composer.unavailable").waitForExistence(timeout: 15))
        showFiles()
        XCTAssertTrue(element("files.openPullRequest").waitForExistence(timeout: 10))
        XCTAssertFalse(element("files.openPullRequest").isEnabled)
        try shoot("open-pr-read-only")
    }

    // MARK: Pull request card

    private func openCard(_ scenario: String = "pull-request-open") throws {
        try openFiles(scenario)
        let button = element("files.openPullRequest")
        XCTAssertTrue(button.waitForExistence(timeout: 15))
        button.tap()
        XCTAssertTrue(element("pullRequest.card").waitForExistence(timeout: 10))
    }

    func testPullRequestCard() throws {
        try openCard()
        XCTAssertTrue(element("pullRequest.row.78").waitForExistence(timeout: 15))
        try shoot("pr-card")
    }

    func testPullRequestCardFixing() throws {
        try openCard()
        let fix = element("pullRequest.askToFix.78")
        XCTAssertTrue(fix.waitForExistence(timeout: 15))
        fix.tap()
        XCTAssertTrue(label("Yak is on it").waitForExistence(timeout: 10))
        try shoot("pr-card-fixing")
    }

    func testPullRequestCardLoading() throws {
        try openFiles("pull-request-slow")
        let button = element("files.openPullRequest")
        XCTAssertTrue(button.waitForExistence(timeout: 15))
        button.tap()
        XCTAssertTrue(element("pullRequest.loading").waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1)
        try shoot("pr-card-loading")
    }

    func testPullRequestCardOffline() throws {
        try openFiles("pull-request-open")
        XCTAssertTrue(element("files.openPullRequest").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        element("files.openPullRequest").tap()
        XCTAssertTrue(element("pullRequest.error").waitForExistence(timeout: 30))
        try shoot("pr-card-offline")
    }
}
