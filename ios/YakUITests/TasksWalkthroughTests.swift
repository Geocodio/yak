import XCTest

/// The quality-bar walkthrough of the Tasks list, the task screen and the Observations list: one screenshot per
/// state in `$YAK_SHOT_DIR`. Run it with `bundle exec fastlane walkthrough only:TasksWalkthroughTests`.
@MainActor final class TasksWalkthroughTests: WalkthroughCase {
    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    /// The cold start lands on the drawer; a compact thread screen opens it with the threads button.
    private func openDrawer() {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(app.otherElements["drawer"].waitForExistence(timeout: 10))
    }

    private func openTilesScreen(_ tile: String, scenario: String) throws {
        try start(scenario, paired: true)
        openDrawer()
        let button = app.buttons[tile]
        XCTAssertTrue(button.waitForExistence(timeout: 10))
        button.tap()
    }

    private func openTask(_ id: String, scenario: String) throws {
        try openTilesScreen("drawer.tile.tasks", scenario: scenario)
        let row = element("tasks.row.\(id)")
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        row.tap()
        XCTAssertTrue(element("task.title").waitForExistence(timeout: 15))
    }

    // MARK: Drawer

    func testDrawerTiles() throws {
        try start("changes", paired: true)
        openDrawer()
        XCTAssertTrue(element("drawer.tile.tasks").waitForExistence(timeout: 10))
        XCTAssertTrue(element("drawer.taskLabel").waitForExistence(timeout: 10))
        try shoot("drawer-tiles")
    }

    // MARK: Tasks list

    func testTasks() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "changes")
        XCTAssertTrue(element("tasks.row.k3m9p2qa").waitForExistence(timeout: 15))
        try shoot("tasks")
    }

    func testTasksFilter() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "changes")
        XCTAssertTrue(element("tasks.row.k3m9p2qa").waitForExistence(timeout: 15))
        element("tasks.filter").tap()
        XCTAssertTrue(app.buttons["Repository"].waitForExistence(timeout: 5))
        try shoot("tasks-filter", audits: false)
    }

    func testTasksEmpty() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "tasks-empty")
        XCTAssertTrue(element("tasks.empty").waitForExistence(timeout: 15))
        try shoot("tasks-empty")
    }

    func testTasksLoading() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "tasks-slow")
        XCTAssertTrue(element("tasks.loading").waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1)
        try shoot("tasks-loading")
        XCTAssertTrue(label("This is taking longer than usual").waitForExistence(timeout: 12))
        try shoot("tasks-slow")
    }

    func testTasksOffline() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "changes")
        XCTAssertTrue(element("tasks.row.k3m9p2qa").waitForExistence(timeout: 15))
        app.terminate()
        try stub.setOnline(false)
        app.launch()
        openDrawer()
        app.buttons["drawer.tile.tasks"].tap()
        XCTAssertTrue(element("tasks.offlineNotice").waitForExistence(timeout: 25))
        try shoot("tasks-offline")
    }

    func testTasksError() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "paired")
        XCTAssertTrue(element("tasks.error").waitForExistence(timeout: 15))
        try shoot("tasks-error")
    }

    // MARK: Task

    func testTask() throws {
        try openTask("k3m9p2qa", scenario: "changes")
        XCTAssertTrue(element("task.decision.approve").waitForExistence(timeout: 10))
        XCTAssertTrue(
            app.webViews.staticTexts["Yak moved the SDK to the new major version."].waitForExistence(timeout: 10))
        try shoot("task")
    }

    func testTaskFromTheDrawer() throws {
        try start("changes", paired: true)
        openDrawer()
        let row = element("drawer.row.task:k3m9p2qa")
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        XCTAssertTrue(element("task.decision.approve").waitForExistence(timeout: 15))
        try shoot("task-from-drawer")
    }

    func testTaskRequestChanges() throws {
        try openTask("k3m9p2qa", scenario: "changes")
        element("task.decision.requestChanges").tap()
        let field = element("task.requestChanges.field")
        XCTAssertTrue(field.waitForExistence(timeout: 10))
        field.typeText("Keep the old client for one more release.")
        try shoot("task-request-changes")
    }

    func testTaskRefused() throws {
        try openTask("k3m9p2qa", scenario: "task-refused")
        element("task.decision.approve").tap()
        XCTAssertTrue(element("task.decision.message").waitForExistence(timeout: 10))
        try shoot("task-refused")
    }

    func testTaskApproving() throws {
        try openTask("k3m9p2qa", scenario: "task-slow-approve")
        element("task.decision.approve").tap()
        XCTAssertTrue(element("task.decision.approving").waitForExistence(timeout: 5))
        try shoot("task-approving")
        XCTAssertTrue(label("Approved by").waitForExistence(timeout: 20))
        try shoot("task-approved")
    }

    func testTaskNotLinked() throws {
        try openTask("k3m9p2qa", scenario: "task-not-linked")
        XCTAssertTrue(element("task.decision.link").waitForExistence(timeout: 10))
        try shoot("task-not-linked")
    }

    func testTaskReadOnly() throws {
        try openTask("r3d0n1yx", scenario: "changes")
        XCTAssertTrue(element("task.readOnly").waitForExistence(timeout: 10))
        try shoot("task-read-only")
    }

    func testTaskAccessCheckFailed() throws {
        try openTask("k3m9p2qa", scenario: "task-access-error")
        XCTAssertTrue(element("task.decision.retry").waitForExistence(timeout: 10))
        try shoot("task-access-error")
    }

    func testTaskTagCoOwner() throws {
        try openTask("k3m9p2qa", scenario: "task-early-warning")
        element("task.earlyWarning").tap()
        XCTAssertTrue(element("task.tag.decline").waitForExistence(timeout: 10))
        try shoot("task-tag", audits: false)
    }

    func testTaskAsked() throws {
        try openTask("k3m9p2qa", scenario: "task-early-warning-tagged")
        XCTAssertTrue(element("task.asked").waitForExistence(timeout: 10))
        try shoot("task-asked")
    }

    func testTaskQuestion() throws {
        try openTask("q5w8e2rt", scenario: "changes")
        XCTAssertTrue(element("task.questions").waitForExistence(timeout: 10))
        try shoot("task-question")
    }

    func testTaskMenu() throws {
        try openTask("m4n8r2tw", scenario: "changes")
        element("task.menu").tap()
        XCTAssertTrue(app.buttons["Open on the web"].waitForExistence(timeout: 5))
        try shoot("task-menu", audits: false)
    }

    func testTaskMissing() throws {
        try stub.play("changes")
        app.launchArguments = [
            "-yakTestInstance", stub.url, "-yakTestToken", "stub-token", "-yakTestOpenTask", "zzzzzzzz",
        ]
        app.launch()
        XCTAssertTrue(element("task.notFound").waitForExistence(timeout: 20))
        try shoot("task-missing")
    }

    func testTaskLoading() throws {
        try openTilesScreen("drawer.tile.tasks", scenario: "changes")
        let row = element("tasks.row.k3m9p2qa")
        XCTAssertTrue(row.waitForExistence(timeout: 15))
        try stub.play("tasks-slow")
        row.tap()
        XCTAssertTrue(element("task.loading").waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1)
        try shoot("task-loading")
        XCTAssertTrue(label("This is taking longer than usual").waitForExistence(timeout: 12))
        try shoot("task-slow")
    }

    // MARK: Observations

    func testObservations() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "changes")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        try shoot("observations")
    }

    func testObservationsDeclined() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "changes")
        XCTAssertTrue(element("observations.row.301").waitForExistence(timeout: 15))
        element("observations.filter.declined").tap()
        XCTAssertTrue(element("observations.row.302").waitForExistence(timeout: 10))
        try shoot("observations-declined")
    }

    func testObservationsEmpty() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "tasks-empty")
        XCTAssertTrue(element("observations.empty").waitForExistence(timeout: 15))
        try shoot("observations-empty")
    }

    func testObservationsError() throws {
        try openTilesScreen("drawer.tile.observations", scenario: "paired")
        XCTAssertTrue(element("observations.error").waitForExistence(timeout: 15))
        try shoot("observations-error")
    }
}
