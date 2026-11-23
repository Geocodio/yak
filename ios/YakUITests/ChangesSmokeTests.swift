import XCTest

/// Smoke tests of the drawer's task items and the Observations list against the stub server.
@MainActor final class ChangesSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openDrawerIfNeeded() {
        if app.otherElements["drawer"].waitForExistence(timeout: 10) { return }
        app.buttons["threads.button"].tap()
    }

    /// A needs-you item that is a former task opens the thread the task lives on, and says it is a task.
    func testADrawerTaskItemOpensItsThread() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        let row = element("drawer.row.task:k3m9p2qa")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(element("drawer.taskLabel").exists)
        row.tap()
        XCTAssertTrue(element("composer.field").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertFalse(app.otherElements["SFSafariView"].exists)
    }

    /// A task link that launches the app, with no thread restored, opens the thread of the task.
    func testATaskLinkAtColdStartOpensItsThread() throws {
        try stub.play("changes")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "task"
        components.queryItems = [
            URLQueryItem(name: "instance", value: stub.url), URLQueryItem(name: "id", value: "k3m9p2qa"),
        ]
        app.open(try XCTUnwrap(components.url))
        XCTAssertTrue(element("composer.field").waitForExistence(timeout: Wait.firstLoad))
    }

    /// The drawer has the Observations tile and no Tasks tile.
    func testTheDrawerHasNoTasksTile() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        XCTAssertTrue(app.buttons["drawer.tile.observations"].waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(app.buttons["drawer.tile.tasks"].exists)
    }

    func testObservationsListShowsTheOutcomes() throws {
        try launch(scenario: "changes")
        openDrawerIfNeeded()
        app.buttons["drawer.tile.observations"].tap()
        XCTAssertTrue(element("observations.row.302").waitForExistence(timeout: Wait.appear))
        try audit()
    }

    /// Opens thread `d0e515ef` from the drawer and switches to the Files pane with the pane menu.
    private func openFiles(scenario: String) throws {
        try launch(scenario: scenario)
        openDrawerIfNeeded()
        let row = element("drawer.row.thread:d0e515ef")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        row.tap()
        showPane("Files")
        XCTAssertTrue(element("files.bar").waitForExistence(timeout: Wait.appear))
    }

    private func showPane(_ name: String) {
        let menu = app.buttons["pane.menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: Wait.appear))
        menu.tap()
        let item = app.buttons.matching(NSPredicate(format: "label == %@", name)).firstMatch
        XCTAssertTrue(item.waitForExistence(timeout: Wait.appear))
        item.tap()
    }

    /// Smoke test 8, Changed half: the file and the bar position survive a switch to Chat and back.
    func testChangedKeepsItsFileAcrossAPaneSwitch() throws {
        try openFiles(scenario: "changes")
        XCTAssertTrue(app.staticTexts["File RetryUpload.php"].waitForExistence(timeout: Wait.appear))
        element("files.next").tap()
        element("files.next").tap()
        XCTAssertTrue(app.staticTexts["File uploads.ts"].waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(element("files.list").label, "File 3 of 4. Show all files")
        showPane("Chat")
        showPane("Files")
        XCTAssertTrue(app.staticTexts["File uploads.ts"].waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(element("files.list").label, "File 3 of 4. Show all files")
        try audit()
    }

    /// A file that was captured again loads by its path: the first file's rows appear, with no error text.
    func testAReplacedFileReloadsByPath() throws {
        try openFiles(scenario: "changes-replaced")
        let row = element("files.changed").descendants(matching: .any).matching(
            NSPredicate(format: "label CONTAINS %@", "retry(3)")
        ).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        XCTAssertFalse(element("files.page.failed").exists)
    }

    /// "Edit file" on a diff page opens that file in the Files editor.
    func testEditFileOnADiffPageOpensTheEditor() throws {
        try openFiles(scenario: "changes-files")
        let edit = element("files.page.edit").firstMatch
        XCTAssertTrue(edit.waitForExistence(timeout: Wait.appear))
        try audit()
        edit.tap()
        let editor = app.textViews["files.editor"]
        XCTAssertTrue(editor.waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue((editor.value as? String ?? "").contains("final class RetryUpload"))
    }

    func testOpenPullRequestOpensAndBecomesTheNumber() throws {
        try openFiles(scenario: "changes")
        let button = element("files.openPullRequest")
        XCTAssertTrue(button.waitForExistence(timeout: Wait.appear))
        button.tap()
        let opened = NSPredicate(format: "label == %@", "Pull request 41, open. Show details")
        wait(for: [XCTNSPredicateExpectation(predicate: opened, object: button)], timeout: Wait.appear)
        XCTAssertEqual(button.label, "Pull request 41, open. Show details")
        try audit()
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                // A bottom toolbar can have a frame that covers the content, so a toolbar only counts when it leaves the
                // centre of the window free.
                let centre = CGPoint(x: auditBars.window.midX, y: auditBars.window.midY)
                let bars =
                    app.navigationBars.allElementsBoundByIndex
                    + app.toolbars.allElementsBoundByIndex.filter { !$0.frame.contains(centre) }
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                    if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                }
                // An element entirely outside the screen shows no pixels to measure.
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                // Text behind a bar is covered by the bar.
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }
}
