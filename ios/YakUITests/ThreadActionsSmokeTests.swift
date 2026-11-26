import XCTest

/// Smoke tests of starring, closing and deleting a thread, against the stub server's `thread-actions` scenarios.
/// The stub edits its lists and pages by what the app sends, so each test follows the thread through the drawer, the
/// header and the closed bar, and reads what the stub received.
@MainActor final class ThreadActionsSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()
    private let thread = "a1b2c3d4"

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }
    private func row(_ id: String) -> XCUIElement { element("drawer.row.thread:\(id)") }

    private func launch(_ scenario: String = "thread-actions") throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(row(thread).waitForExistence(timeout: Wait.firstLoad))
    }

    /// The requests the stub took, one per line.
    private func log() -> String { (try? stub.get("thread-actions/log")) ?? "" }

    private func waitForLog(containing text: String) {
        let deadline = Date().addingTimeInterval(Wait.appear)
        while !log().contains(text), Date() < deadline { Thread.sleep(forTimeInterval: 0.2) }
        XCTAssertTrue(log().contains(text), "the stub did not receive \(text)")
    }

    private func longPress(_ id: String) {
        row(id).press(forDuration: 1.2)
    }

    /// The item of the system menu or sheet that is open, by its words.
    private func button(_ label: String) -> XCUIElement {
        app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", label)).firstMatch
    }

    /// The toast with these words, found by its identifier and its label together.
    private func toast(saying words: String) -> XCUIElement {
        app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier == 'drawer.toast' AND label CONTAINS %@", words)
        ).firstMatch
    }

    private func openThread() {
        row(thread).tap()
        XCTAssertTrue(app.buttons["thread.menu"].waitForExistence(timeout: Wait.appear))
    }

    private func openHeaderMenu() {
        app.buttons["thread.menu"].tap()
    }

    /// Cancel on the question that asks before a close or a delete.
    private func dismissDialog() {
        app.alerts.buttons["Cancel"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: app.alerts.firstMatch)], timeout: Wait.appear)
    }

    private func dismissMenu() {
        app.coordinate(withNormalizedOffset: CGVector(dx: 0.1, dy: 0.5)).tap()
    }

    private func openDrawer() {
        if app.otherElements["drawer"].exists, element("drawer.tab.closed").isHittable { return }
        app.buttons["threads.button"].tap()
        XCTAssertTrue(element("drawer.tab.closed").waitForExistence(timeout: Wait.appear))
    }

    private func auditDrawer() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }

    // MARK: Star

    /// A star from the header's menu shows the mark beside the title, the item then reads "Remove star", and the star
    /// can be taken off again.
    func testStarAndUnstarFromTheHeaderMenu() throws {
        try launch()
        openThread()
        XCTAssertFalse(element("thread.starMark").exists)
        openHeaderMenu()
        button("Star thread").tap()
        XCTAssertTrue(element("thread.starMark").waitForExistence(timeout: Wait.appear))
        waitForLog(containing: "POST threads/\(thread)/star {\"starred\":true}")
        XCTAssertTrue(element("thread.title").label.contains("starred"))
        openHeaderMenu()
        XCTAssertTrue(button("Remove star").waitForExistence(timeout: Wait.appear))
        button("Remove star").tap()
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: element("thread.starMark"))], timeout: Wait.appear)
        waitForLog(containing: "{\"starred\":false}")
    }

    /// A long press on a row stars the thread: the row gets a star and a Starred group opens above the others, and
    /// the same press takes the star off.
    func testStarAndUnstarFromALongPressOnARow() throws {
        try launch()
        longPress(thread)
        button("Star thread").tap()
        XCTAssertTrue(element("drawer.starMark.thread:\(thread)").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["Starred"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(row(thread).label.contains("Starred"))
        try auditDrawer()
        longPress(thread)
        button("Remove star").tap()
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: element("drawer.starMark.thread:\(thread)"))],
            timeout: Wait.appear)
    }

    // MARK: Close

    /// Close asks first and says plainly that it cannot be undone; Cancel sends nothing. After the close the header
    /// carries the Closed mark, the message field gives way to the closed bar, and the row reads Closed in the Closed
    /// tab.
    func testCloseAsksAndThenTheThreadReadsClosed() throws {
        try launch()
        openThread()
        openHeaderMenu()
        button("Close thread").tap()
        XCTAssertTrue(app.alerts.staticTexts["Close this thread?"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(
            app.alerts.staticTexts[
                "Its sandbox is destroyed and its 1 sub-thread closes too. You can still read it. It cannot be reopened."
            ].exists)
        dismissDialog()
        XCTAssertFalse(log().contains("/close"))
        openHeaderMenu()
        button("Close thread").tap()
        app.alerts.buttons["Close thread"].firstMatch.tap()
        XCTAssertTrue(element("thread.closedMark").waitForExistence(timeout: Wait.appear))
        waitForLog(containing: "POST threads/\(thread)/close")
        XCTAssertTrue(element("composer.new").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(app.staticTexts["This thread is closed."].exists || element("composer.unavailable").exists)
        try auditThreadScreen(app)
        openHeaderMenu()
        XCTAssertFalse(app.buttons["Close thread"].exists)
        XCTAssertTrue(app.buttons["Delete thread"].exists)
    }

    // MARK: Delete

    /// Delete asks, naming what goes and what stays; then the row leaves the list and the drawer says so.
    func testDeleteAsksThenTheRowLeavesAndTheToastShows() throws {
        try launch()
        longPress(thread)
        button("Delete thread").tap()
        XCTAssertTrue(app.alerts.staticTexts["Delete this thread?"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(
            app.alerts.staticTexts.matching(NSPredicate(format: "label CONTAINS %@", "are removed for good")).firstMatch
                .exists
        )
        dismissDialog()
        XCTAssertFalse(log().contains("DELETE"))
        longPress(thread)
        button("Delete thread").tap()
        app.alerts.buttons["Delete thread"].firstMatch.tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: row(thread))], timeout: Wait.appear)
        XCTAssertTrue(toast(saying: "Thread deleted.").waitForExistence(timeout: Wait.appear))
        waitForLog(containing: "DELETE threads/\(thread)")

    }

    /// Deleting the open thread leaves it for the drawer, with the toast.
    func testDeletingTheOpenThreadGoesToTheDrawer() throws {
        try launch()
        openThread()
        openHeaderMenu()
        button("Delete thread").tap()
        // The thread has a branch and a pull request, so the question names both.
        XCTAssertTrue(
            app.alerts.staticTexts.matching(
                NSPredicate(
                    format: "label CONTAINS %@",
                    "The branch yak/retry-failed-rows and pull request #2301 on GitHub stay.")
            ).firstMatch.waitForExistence(timeout: Wait.appear))
        app.alerts.buttons["Delete thread"].firstMatch.tap()
        XCTAssertTrue(toast(saying: "Thread deleted.").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(row(thread).exists)
        XCTAssertTrue(element("drawer").exists)
    }

    /// While the server destroys the sandboxes the row dims and says Deleting, and its other actions wait.
    func testTheRowSaysDeletingWhileTheServerWorks() throws {
        try launch("thread-actions-slow")
        longPress(thread)
        button("Delete thread").tap()
        app.alerts.buttons["Delete thread"].firstMatch.tap()
        let deleting = NSPredicate(format: "label CONTAINS %@", "Deleting")
        wait(for: [XCTNSPredicateExpectation(predicate: deleting, object: row(thread))], timeout: Wait.appear)
        longPress(thread)
        let star = button("Star thread")
        XCTAssertTrue(star.waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(star.isEnabled)
        XCTAssertFalse(button("Close thread").isEnabled)
        dismissMenu()
        // The stub holds the answer until the test lets it go.
        try stub.post("thread-actions/release", "go")
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: row(thread))], timeout: Wait.appear)
    }

    /// A sandbox the server could not destroy keeps the thread and shows the server's sentence; Try again finishes it.
    func testASandboxThatCannotBeDestroyedOffersTryAgain() throws {
        try launch("thread-actions-conflict")
        longPress(thread)
        button("Delete thread").tap()
        app.alerts.buttons["Delete thread"].firstMatch.tap()
        XCTAssertTrue(app.alerts["Could not delete the thread"].waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(
            app.alerts.staticTexts["The sandbox could not be destroyed yet. Try again in a moment."].exists)
        XCTAssertTrue(row(thread).exists)
        app.alerts.buttons["Try again"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: row(thread))], timeout: Wait.appear)
        XCTAssertEqual(log().components(separatedBy: "DELETE threads/\(thread)").count - 1, 2)
    }

    // MARK: Who may

    /// A share recipient may star and copy the link, and sees neither Close nor Delete, in the row's menu and in the
    /// header's.
    func testAShareRecipientSeesNoCloseOrDelete() throws {
        try launch("thread-actions-recipient")
        longPress(thread)
        XCTAssertTrue(button("Star thread").waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(button("Copy thread link").exists)
        XCTAssertFalse(app.buttons["Close thread"].exists)
        XCTAssertFalse(app.buttons["Delete thread"].exists)
        dismissMenu()
        openThread()
        openHeaderMenu()
        XCTAssertTrue(button("Star thread").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(app.buttons["Close thread"].exists)
        XCTAssertFalse(app.buttons["Delete thread"].exists)
    }

    /// A sub-thread's menu has no Star, Close or Delete.
    func testASubThreadRowHasNoStarCloseOrDelete() throws {
        try launch()
        app.buttons["drawer.toggle.thread:\(thread)"].tap()
        let child = row("e5f6g7h8")
        XCTAssertTrue(child.waitForExistence(timeout: Wait.appear))
        child.press(forDuration: 1.2)
        XCTAssertTrue(button("Copy thread link").waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(app.buttons["Star thread"].exists)
        XCTAssertFalse(app.buttons["Close thread"].exists)
        XCTAssertFalse(app.buttons["Delete thread"].exists)
    }

    // MARK: Offline

    /// Offline, the items that need the server are disabled and say why; the link stays.
    func testOfflineTheServerItemsAreDisabled() throws {
        try launch()
        try stub.setOnline(false)
        XCTAssertTrue(element("drawer.offlineNotice").waitForExistence(timeout: Wait.appear))
        longPress(thread)
        for label in ["Star thread", "Close thread", "Delete thread"] {
            let item = button(label)
            XCTAssertTrue(item.waitForExistence(timeout: Wait.appear), label)
            XCTAssertFalse(item.isEnabled, label)
        }
        XCTAssertTrue(
            app.staticTexts["Not available offline"].firstMatch.exists
                || button("Star thread").label.contains("Not available offline"))
        XCTAssertTrue(button("Copy thread link").isEnabled)
    }

    // MARK: Closed rows

    /// A closed thread reads dimmed with Closed as its detail in the Closed tab, and the closed drawer passes the audit.
    func testAClosedThreadMovesToTheClosedTab() throws {
        try launch()
        openThread()
        openHeaderMenu()
        button("Close thread").tap()
        app.alerts.buttons["Close thread"].firstMatch.tap()
        XCTAssertTrue(element("thread.closedMark").waitForExistence(timeout: Wait.appear))
        openDrawer()
        element("drawer.tab.closed").tap()
        XCTAssertTrue(row(thread).waitForExistence(timeout: Wait.appear))
        try auditDrawer()
    }

    /// A drawer with one row, the one with the PagerDuty badge, passes the whole audit.
    func testADrawerWithASingleRowPassesTheFullAudit() throws {
        try stub.play("thread-actions-single")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(row(thread).waitForExistence(timeout: Wait.firstLoad))
        XCTAssertFalse(element("drawer.row.thread:d0e515ef").exists)
        try auditDrawer()
    }
}
