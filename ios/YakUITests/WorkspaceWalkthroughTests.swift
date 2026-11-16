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
        try shootThread("terminal")
    }

    func testTerminalKeyboard() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        output.tap()
        try shootThread("terminal-keyboard")
    }

    func testTerminalShellMenu() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        app.buttons["terminal.key.shells"].tap()
        app.buttons["terminal.shells.new"].tap()
        waitForOutput("stub$")
        app.buttons["terminal.key.shells"].tap()
        XCTAssertTrue(app.buttons["terminal.shells.row.main"].waitForExistence(timeout: 5))
        // The shell menu is the system's own popover; the audit cannot judge text on it.
        try shoot("terminal-shells", audits: false)
    }

    func testTerminalWaking() throws {
        try openTerminal("terminal-waking")
        XCTAssertTrue(state("terminal.state").waitForExistence(timeout: 10))
        try shootThread("terminal-waking")
    }

    func testTerminalSlow() throws {
        try openTerminal("terminal-slow-wake")
        XCTAssertTrue(state("terminal.state").waitForExistence(timeout: 10))
        XCTAssertTrue(label("This is taking longer than usual").waitForExistence(timeout: 45))
        try shootThread("terminal-slow")
    }

    func testTerminalWaiting() throws {
        try openTerminal("terminal-waiting")
        XCTAssertTrue(label("Waiting for a free sandbox slot").waitForExistence(timeout: 10))
        try shootThread("terminal-waiting")
    }

    func testTerminalWaitedTooLong() throws {
        try start("terminal-waiting-forever", paired: true, extraArguments: ["-yakTestShortWaits", "YES"])
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        XCTAssertTrue(label("Still no free slot").waitForExistence(timeout: 20))
        try shootThread("terminal-waited")
    }

    func testTerminalLost() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.post("terminal/close", "1011")
        XCTAssertTrue(state("terminal.banner").waitForExistence(timeout: 10))
        try shootThread("terminal-lost")
    }

    func testTerminalOffline() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        XCTAssertTrue(label("Offline. The shell reconnects").waitForExistence(timeout: 10))
        app.buttons["terminal.key.escape"].tap()
        try shootThread("terminal-offline")
    }

    func testTerminalEnded() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        output.tap()
        app.typeText("exit\n")
        XCTAssertTrue(label("The shell ended").waitForExistence(timeout: 10))
        try shootThread("terminal-ended")
    }

    func testTerminalIdle() throws {
        try openTerminal("workspace")
        waitForOutput("stub$")
        try stub.post("terminal/close", "4408")
        XCTAssertTrue(label("closed after 30 minutes").waitForExistence(timeout: 10))
        try shootThread("terminal-idle")
    }

    private func statesCase(_ thread: String, expecting text: String, shot: String) throws {
        try openTerminal("terminal-states", thread: thread)
        XCTAssertTrue(label(text).waitForExistence(timeout: 15))
        try shootThread(shot)
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
        try shootThread("terminal-restoring")
    }

    func testTerminalStillRestoring() throws {
        try start("terminal-restoring-forever", paired: true, extraArguments: ["-yakTestShortWaits", "YES"])
        app.openThread("termg0ne")
        app.openPane("Terminal")
        state("terminal.state.action").tap()
        XCTAssertTrue(label("Still restoring").waitForExistence(timeout: 25))
        try shootThread("terminal-still-restoring")
    }

    func testTerminalNoSandbox() throws {
        try statesCase("termn0sb", expecting: "no sandbox yet", shot: "terminal-no-sandbox")
    }

    func testTerminalRestoreFailed() throws {
        try statesCase("termb1kd", expecting: "could not be restored", shot: "terminal-restore-failed")
    }

    func testTerminalTooMany() throws {
        try statesCase("term4429", expecting: "Too many shells", shot: "terminal-too-many")
    }

    // MARK: Files

    private func openFiles(_ scenario: String, thread: String = "d0e515ef", arguments: [String] = []) throws {
        try start(scenario, paired: true, extraArguments: arguments)
        app.openThread(thread)
        app.openPane("Files")
    }

    private func openSource() throws {
        try openFiles("workspace")
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 15))
        state("files.row.src").tap()
        XCTAssertTrue(state("files.row.src/Importer.php").waitForExistence(timeout: 10))
    }

    private func openImporter(_ scenario: String = "workspace", arguments: [String] = []) throws {
        try openFiles(scenario, arguments: arguments)
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 15))
        state("files.row.src").tap()
        XCTAssertTrue(state("files.row.src/Importer.php").waitForExistence(timeout: 10))
        state("files.row.src/Importer.php").tap()
        XCTAssertTrue(state("files.viewer").waitForExistence(timeout: 10))
    }

    func testFilesTree() throws {
        try openSource()
        try shoot("files-tree")
    }

    func testFilesLoading() throws {
        try openFiles("terminal-waking")
        XCTAssertTrue(state("files.state").waitForExistence(timeout: 5))
        Thread.sleep(forTimeInterval: 1)
        try shootThread("files-loading")
    }

    func testFilesFind() throws {
        try openFiles("workspace")
        let field = state("files.find")
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        field.tap()
        field.typeText("import")
        XCTAssertTrue(state("files.result.src/Importer.php").waitForExistence(timeout: 10))
        try shootThread("files-find")
    }

    func testFilesFindNone() throws {
        try openFiles("workspace")
        let field = state("files.find")
        XCTAssertTrue(field.waitForExistence(timeout: 15))
        field.tap()
        field.typeText("webhook")
        XCTAssertTrue(label("No file matches").waitForExistence(timeout: 10))
        try shootThread("files-find-none")
    }

    func testFilesEmptyFolder() throws {
        try openFiles("workspace")
        XCTAssertTrue(state("files.row.empty").waitForExistence(timeout: 15))
        state("files.row.empty").tap()
        XCTAssertTrue(label("This folder is empty").waitForExistence(timeout: 10))
        try shoot("files-empty-folder")
    }

    func testFilesTruncated() throws {
        try openSource()
        app.swipeUp()
        XCTAssertTrue(label("Showing the first 2,000 items").waitForExistence(timeout: 10))
        try shoot("files-truncated")
    }

    private func filesState(_ thread: String, expecting text: String, shot: String, arguments: [String] = []) throws {
        try openFiles("files-states", thread: thread, arguments: arguments)
        XCTAssertTrue(label(text).waitForExistence(timeout: 20))
        try shootThread(shot)
    }

    func testFilesWaking() throws {
        try filesState("fwak1ng0", expecting: "Waking the sandbox", shot: "files-waking")
    }

    func testFilesSlot() throws {
        try filesState("fs10t000", expecting: "Waiting for a free sandbox slot", shot: "files-slot")
    }

    func testFilesRestoring() throws {
        try openFiles("files-states", thread: "fg0ne000")
        state("files.restore").tap()
        XCTAssertTrue(label("Restoring the sandbox").firstMatch.waitForExistence(timeout: 10))
        try shootThread("files-restoring")
    }

    func testFilesStillWaking() throws {
        try openFiles("files-states", thread: "fs10t000", arguments: ["-yakTestShortWaits", "YES"])
        XCTAssertTrue(label("Still waking").waitForExistence(timeout: 25))
        try shootThread("files-still-waking")
    }

    func testFilesStillRestoring() throws {
        try openFiles("files-states", thread: "fr3st0re", arguments: ["-yakTestShortWaits", "YES"])
        XCTAssertTrue(label("Restoring the sandbox").firstMatch.waitForExistence(timeout: 15))
        XCTAssertTrue(label("Still restoring").waitForExistence(timeout: 30))
        try shootThread("files-still-restoring")
    }

    func testFilesAsleep() throws {
        try filesState("fas1eep0", expecting: "The sandbox is asleep", shot: "files-asleep")
    }

    func testFilesRemoved() throws {
        try filesState("fg0ne000", expecting: "The sandbox was removed", shot: "files-removed")
    }

    func testFilesClosed() throws {
        try filesState("fc10sed0", expecting: "This thread is closed", shot: "files-closed")
    }

    func testFilesComputer() throws {
        try filesState("fdev1ce0", expecting: "runs on a computer", shot: "files-computer")
    }

    func testFilesError() throws {
        try filesState("fbr0ken0", expecting: "The sandbox did not answer", shot: "files-error")
    }

    func testFilesOfflineTree() throws {
        try openFiles("workspace")
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 15))
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        state("files.row.src").tap()
        XCTAssertTrue(label("Offline. Showing what was loaded").waitForExistence(timeout: 15))
        try shootThread("files-offline-tree")
    }

    func testFilesSlow() throws {
        try openFiles("files-slow")
        XCTAssertTrue(state("files.state").waitForExistence(timeout: 5))
        XCTAssertTrue(state("files.state.action").waitForExistence(timeout: 20))
        try shootThread("files-slow")
    }

    func testFilesOfflineEmpty() throws {
        try stub.play("workspace")
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        try start("workspace", paired: true)
        app.openThread("d0e515ef")
        app.openPane("Files")
        XCTAssertTrue(label("You are offline").firstMatch.waitForExistence(timeout: 20))
        try shootThread("files-offline-empty")
    }

    // MARK: File viewer

    func testFileViewer() throws {
        try openImporter()
        try shootThread("file-viewer")
    }

    func testFileViewerLoading() throws {
        try openFiles("file-slow-read")
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 15))
        state("files.row.src").tap()
        XCTAssertTrue(state("files.row.src/Importer.php").waitForExistence(timeout: 10))
        state("files.row.src/Importer.php").tap()
        XCTAssertTrue(state("files.viewer.loading").waitForExistence(timeout: 10))
        try shootThread("file-viewer-loading")
    }

    func testFileViewerWrapped() throws {
        try openImporter()
        state("files.viewer.menu").tap()
        app.buttons["Wrap lines"].tap()
        try shootThread("file-viewer-wrapped")
    }

    func testFileBinary() throws {
        try openSource()
        state("files.row.src/logo.png").tap()
        XCTAssertTrue(label("This is a binary file").waitForExistence(timeout: 10))
        try shootThread("file-binary")
    }

    func testFileTooLarge() throws {
        try openSource()
        state("files.row.src/sample.csv").tap()
        XCTAssertTrue(label("This file is too large to show").waitForExistence(timeout: 10))
        try shootThread("file-too-large")
    }

    func testFileNotFound() throws {
        try openSource()
        state("files.row.src/Gone.php").tap()
        XCTAssertTrue(label("This file is not there anymore").waitForExistence(timeout: 10))
        try shootThread("file-not-found")
    }

    func testFileOffline() throws {
        try openImporter()
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        state("files.viewer.back").tap()
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 10))
        state("files.row.src/Importer.php").tap()
        try shootThread("file-offline")
    }

    func testFileReadOnly() throws {
        try openImporter("files-read-only")
        XCTAssertFalse(state("files.viewer.edit").exists)
        try shootThread("file-read-only")
    }

    // MARK: File editor

    private func startEditing(
        _ scenario: String = "workspace", typing text: String = "// note\n", arguments: [String] = []
    ) throws {
        try openImporter(scenario, arguments: arguments)
        state("files.viewer.edit").tap()
        XCTAssertTrue(state("files.editor").waitForExistence(timeout: 10))
        let editor = app.textViews["files.editor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        editor.coordinate(withNormalizedOffset: CGVector(dx: 0.6, dy: 0.05)).tap()
        _ = app.keyboards.firstMatch.waitForExistence(timeout: 3)
        app.typeText(text)
    }

    func testFileEditor() throws {
        try startEditing()
        try shootThread("file-editor")
    }

    func testFileSaved() throws {
        try startEditing()
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.editor.savedLine").waitForExistence(timeout: 10))
        try shootThread("file-saved")
    }

    func testFileEditorSaving() throws {
        try startEditing("file-slow-save")
        state("files.editor.save").tap()
        Thread.sleep(forTimeInterval: 1)
        XCTAssertFalse(state("files.editor.savedLine").exists)
        try shootThread("file-editor-saving")
    }

    func testFileConflictDeleted() throws {
        try startEditing("files-conflict-deleted")
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.conflict").waitForExistence(timeout: 10))
        XCTAssertTrue(label("The file was deleted since you opened it.").waitForExistence(timeout: 5))
        try shootThread("file-conflict-deleted")
    }

    func testFileDraftRestored() throws {
        try startEditing(arguments: ["-yakTestKeepFiles", "fresh"])
        XCUIDevice.shared.press(.home)
        Thread.sleep(forTimeInterval: 1)
        app.terminate()
        app.launchArguments = app.launchArguments.map { $0 == "fresh" ? "again" : $0 }
        app.launch()
        app.openThread("d0e515ef")
        app.openPane("Files")
        XCTAssertTrue(state("files.row.src").waitForExistence(timeout: 15))
        state("files.row.src").tap()
        XCTAssertTrue(state("files.row.src/Importer.php").waitForExistence(timeout: 10))
        state("files.row.src/Importer.php").tap()
        XCTAssertTrue(label("Restored the text you had not saved.").waitForExistence(timeout: 15))
        try shootThread("file-draft-restored")
    }

    func testFileConflict() throws {
        try startEditing("files-conflict")
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.conflict").waitForExistence(timeout: 10))
        try shootThread("file-conflict")
    }

    func testFileSaveOffline() throws {
        try startEditing()
        try stub.setOnline(false)
        defer { try? stub.setOnline(true) }
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.editor.failedLine").waitForExistence(timeout: 20))
        try shootThread("file-save-offline")
    }

    func testFileSaveClosed() throws {
        try startEditing("files-save-closed")
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.editor.failedLine").waitForExistence(timeout: 10))
        try shootThread("file-save-closed")
    }

    func testFileSaveAsleep() throws {
        try startEditing("files-save-asleep")
        state("files.editor.save").tap()
        XCTAssertTrue(state("files.editor.failedLine").waitForExistence(timeout: 10))
        try shootThread("file-save-asleep")
    }

    func testFileDiscardConfirm() throws {
        try startEditing()
        state("files.editor.cancel").tap()
        XCTAssertTrue(app.buttons["Discard changes"].waitForExistence(timeout: 5))
        // The confirmation dialog is the system's own surface; the audit cannot judge it.
        try shoot("file-discard-confirm", audits: false)
    }
}
