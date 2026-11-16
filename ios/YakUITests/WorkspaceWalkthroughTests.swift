import XCTest

/// The quality-bar walkthrough of the Terminal pane: one screenshot per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:WorkspaceWalkthroughTests`.
@MainActor final class WorkspaceWalkthroughTests: WalkthroughCase {
    private var output: XCUIElement { app.descendants(matching: .any)["terminal.view"] }

    private func openTerminal(_ scenario: String, thread: String = "d0e515ef") throws {
        try start(scenario, paired: true)
        app.openThread(thread)
        app.openPane("Terminal")
    }

    private func waitForOutput(_ text: String, timeout: TimeInterval = 15) {
        let predicate = NSPredicate(format: "value CONTAINS %@", text)
        wait(for: [XCTNSPredicateExpectation(predicate: predicate, object: output)], timeout: timeout)
    }

    private func state(_ element: String) -> XCUIElement { app.descendants(matching: .any)[element] }

    func testTerminal() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        output.tap()
        app.typeText("git status -s\n")
        waitForOutput("ImporterTest")
        if app.buttons["terminal.key.keyboard"].exists { app.buttons["terminal.key.keyboard"].tap() }
        try shoot("terminal", audits: false)
    }

    func testTerminalKeyboard() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        output.tap()
        try shoot("terminal-keyboard", audits: false)
    }

    func testTerminalShellMenu() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        app.buttons["terminal.key.shells"].tap()
        app.buttons["terminal.shells.new"].tap()
        waitForOutput("stub$")
        app.buttons["terminal.key.shells"].tap()
        XCTAssertTrue(app.buttons["terminal.shells.row.main"].waitForExistence(timeout: 5))
        try shoot("terminal-shells", audits: false)
    }

    func testTerminalWaking() throws {
        try openTerminal("terminal-waking")
        XCTAssertTrue(state("terminal.state").waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1)
        try shoot("terminal-waking", audits: false)
    }

    func testTerminalSlow() throws {
        try openTerminal("terminal-slow-wake")
        XCTAssertTrue(state("terminal.state").waitForExistence(timeout: 10))
        XCTAssertTrue(label("This is taking longer than usual").waitForExistence(timeout: 14))
        try shoot("terminal-slow", audits: false)
    }

    func testTerminalWaiting() throws {
        try openTerminal("terminal-waiting")
        XCTAssertTrue(label("Waiting for a free sandbox slot").waitForExistence(timeout: 10))
        try shoot("terminal-waiting", audits: false)
    }

    func testTerminalWaitedTooLong() throws {
        try start("terminal-waiting-forever", paired: true, extraArguments: ["-yakTestShortWaits", "YES"])
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        XCTAssertTrue(label("Still no free slot").waitForExistence(timeout: 20))
        try shoot("terminal-waited", audits: false)
    }

    func testTerminalLost() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.post("terminal/close", "1011")
        XCTAssertTrue(state("terminal.banner").waitForExistence(timeout: 10))
        try shoot("terminal-lost", audits: false)
    }

    func testTerminalOffline() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        XCTAssertTrue(label("Offline. The shell reconnects").waitForExistence(timeout: 10))
        app.buttons["terminal.key.escape"].tap()
        try shoot("terminal-offline", audits: false)
    }

    func testTerminalEnded() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        output.tap()
        app.typeText("exit\n")
        XCTAssertTrue(label("The shell ended").waitForExistence(timeout: 10))
        try shoot("terminal-ended", audits: false)
    }

    func testTerminalIdle() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.post("terminal/close", "4408")
        XCTAssertTrue(label("closed after 30 minutes").waitForExistence(timeout: 10))
        try shoot("terminal-idle", audits: false)
    }

    func testTerminalTooMany() throws {
        try openTerminal("terminal-states", thread: "term4429")
        XCTAssertTrue(label("Too many shells are open").waitForExistence(timeout: 15))
        try shoot("terminal-too-many", audits: false)
    }

    private func statesCase(_ thread: String, expecting text: String, shot: String) throws {
        try openTerminal("terminal-states", thread: thread)
        XCTAssertTrue(label(text).waitForExistence(timeout: 15))
        try shoot(shot, audits: false)
    }

    func testTerminalOrigin() throws {
        try statesCase("term0rgn", expecting: "does not accept the terminal", shot: "terminal-origin")
    }

    func testTerminalReadOnly() throws {
        try statesCase("termf0rb", expecting: "You can read this thread", shot: "terminal-read-only")
    }

    func testTerminalClosed() throws {
        try statesCase("termc10s", expecting: "This thread is closed", shot: "terminal-closed")
    }

    func testTerminalComputer() throws {
        try statesCase("termdev1", expecting: "This thread runs on a computer", shot: "terminal-computer")
    }

    func testTerminalRemoved() throws {
        try statesCase("termg0ne", expecting: "The sandbox was removed", shot: "terminal-removed")
    }

    func testTerminalRestoring() throws {
        try openTerminal("terminal-states", thread: "termg0ne")
        state("terminal.state.action").tap()
        XCTAssertTrue(label("Restoring the sandbox").firstMatch.waitForExistence(timeout: 10))
        Thread.sleep(forTimeInterval: 1)
        try shoot("terminal-restoring", audits: false)
    }

    func testTerminalStillRestoring() throws {
        try start("terminal-restoring-forever", paired: true, extraArguments: ["-yakTestShortWaits", "YES"])
        app.openThread("termg0ne")
        app.openPane("Terminal")
        state("terminal.state.action").tap()
        XCTAssertTrue(label("Still restoring").waitForExistence(timeout: 25))
        try shoot("terminal-still-restoring", audits: false)
    }

    func testTerminalNoSandbox() throws {
        try statesCase("termn0sb", expecting: "no sandbox yet", shot: "terminal-no-sandbox")
    }

    func testTerminalRestoreFailed() throws {
        try statesCase("termb1kd", expecting: "could not be restored", shot: "terminal-restore-failed")
    }
}
