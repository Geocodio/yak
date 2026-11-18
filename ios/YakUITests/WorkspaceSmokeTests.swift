import XCTest

extension XCUIApplication {
    /// Opens a thread from the drawer, which shows first on a phone.
    func openThread(_ id: String) {
        let row = descendants(matching: .any)["drawer.row.thread:\(id)"]
        if !descendants(matching: .any)["drawer.list"].waitForExistence(timeout: 60) { buttons["threads.button"].tap() }
        XCTAssertTrue(descendants(matching: .any)["drawer.list"].waitForExistence(timeout: 60))
        // A list of many threads builds the later rows only after a scroll.
        var scrolls = 0
        while !row.isHittable, scrolls < 6 {
            if row.waitForExistence(timeout: 60), row.isHittable { break }
            swipeUp()
            scrolls += 1
        }
        // One more scroll lifts the row clear of the drawer's search bar at the bottom.
        if scrolls > 0 { swipeUp() }
        XCTAssertTrue(row.exists, "the thread row is not in the drawer")
        row.tap()
    }

    /// Opens the Files pane and switches it to Everything, which is where these tests work; the pane opens on Changed
    /// when the thread has changes.
    func openFilesEverything() {
        openPane("Files")
        let everything = buttons["Everything"]
        if everything.waitForExistence(timeout: 60) { everything.tap() }
    }

    /// Switches to a pane by its name through the pane menu or, where the panes are a row, its button.
    func openPane(_ name: String) {
        let menu = buttons["pane.menu"]
        if menu.waitForExistence(timeout: 60) {
            menu.tap()
            let byIdentifier = descendants(matching: .any)["pane.menu.\(name.lowercased())"]
            let item =
                byIdentifier.waitForExistence(timeout: 60)
                ? byIdentifier : buttons.matching(identifier: name).firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 60))
            item.tap()
        } else {
            let row = buttons["pane.row.\(name.lowercased())"]
            XCTAssertTrue(row.waitForExistence(timeout: 60))
            row.tap()
        }
    }
}

/// Smoke tests of the Terminal and Files panes against the stub server.
@MainActor final class WorkspaceSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String, arguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + arguments
        app.launch()
    }

    private var output: XCUIElement { app.descendants(matching: .any)["terminal.view"] }

    private func waitForValue(_ element: XCUIElement, containing text: String, timeout: TimeInterval = 10) {
        let predicate = NSPredicate(format: "value CONTAINS %@", text)
        wait(for: [XCTNSPredicateExpectation(predicate: predicate, object: element)], timeout: timeout)
        XCTAssertTrue((element.value as? String ?? "").contains(text), "\(text) is not in the output")
    }

    func testTerminalOpensTypesAndReconnects() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        XCTAssertTrue(output.waitForExistence(timeout: 60))
        waitForValue(output, containing: "stub$")
        try audit()
        output.tap()
        app.typeText("echo hello\n")
        waitForValue(output, containing: "hello")
        try stub.post("terminal/close", "1011")
        // The banner shows only while the shell reconnects, which a slow machine can pass between two queries, so the
        // proof is the notice in the new shell's output.
        waitForValue(output, containing: "New shell. The connection dropped at", timeout: 60)
        XCTAssertEqual(try stub.get("terminal/opened"), "2")
    }

    /// Command 3 and Command 4 focus Files and the Terminal.
    func testCommandThreeAndFourFocusFilesAndTerminal() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        XCTAssertTrue(app.buttons["pane.menu"].waitForExistence(timeout: 60) || app.buttons["pane.row.chat"].exists)
        app.typeKey("4", modifierFlags: .command)
        XCTAssertTrue(output.waitForExistence(timeout: 60))
        app.typeKey("3", modifierFlags: .command)
        XCTAssertTrue(element("files").waitForExistence(timeout: 60))
        XCTAssertTrue(element("files").isHittable)
    }

    func testTerminalIdleCloseDoesNotReconnect() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        waitForValue(output, containing: "stub$", timeout: 15)
        try stub.post("terminal/close", "4408")
        XCTAssertTrue(
            app.staticTexts["This shell closed after 30 minutes without input."].waitForExistence(timeout: 60))
        Thread.sleep(forTimeInterval: 5)
        XCTAssertEqual(try stub.get("terminal/opened"), "1")
    }

    func testPaneSwitchKeepsTheShell() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        waitForValue(output, containing: "stub$", timeout: 15)
        app.openPane("Chat")
        app.openPane("Terminal")
        XCTAssertEqual(try stub.get("terminal/opened"), "1")
        XCTAssertTrue((output.value as? String ?? "").contains("stub$"))
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    private func openFile(_ scenario: String = "workspace", arguments: [String] = []) throws {
        try launch(scenario: scenario, arguments: arguments)
        app.openThread("d0e515ef")
        app.openFilesEverything()
        XCTAssertTrue(element("files.row.src").waitForExistence(timeout: 60))
        element("files.row.src").tap()
        XCTAssertTrue(element("files.row.src/Importer.php").waitForExistence(timeout: 60))
        element("files.row.src/Importer.php").tap()
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 60))
    }

    private func typeIntoEditor(_ text: String) {
        let editor = app.textViews["files.editor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 60))
        editor.coordinate(withNormalizedOffset: CGVector(dx: 0.6, dy: 0.05)).tap()
        app.typeText(text)
    }

    func testFilesTreeOpensAFolderAndAFile() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openFilesEverything()
        XCTAssertTrue(element("files.row.src").waitForExistence(timeout: 60))
        element("files.row.src").tap()
        let file = element("files.row.src/Importer.php")
        XCTAssertTrue(file.waitForExistence(timeout: 60))
        XCTAssertTrue(file.label.contains("modified"))
        try audit()
        file.tap()
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 60))
        try audit()
    }

    func testChangedKeepsItsBarAfterEverythingWasOpened() throws {
        try openFile()
        app.buttons["Changed"].tap()
        XCTAssertTrue(element("files.openPullRequest").waitForExistence(timeout: 60))
        XCTAssertTrue(element("files.bar").exists)
        XCTAssertFalse(element("files.find").exists)
        XCTAssertFalse(element("files.viewer.bar").exists)
    }

    /// The tree's marks and the thread's change list are separate reads, so the file may not be in the list yet; the
    /// button still takes the person to Changed, and the list shows the file once it has it.
    func testViewDiffShowsChangedMode() throws {
        try openFile()
        element("files.viewer.diff").tap()
        XCTAssertTrue(element("files.changed").waitForExistence(timeout: 60))
        XCTAssertTrue(element("files.bar").waitForExistence(timeout: 60))
        XCTAssertFalse(element("files.viewer.bar").exists)
    }

    func testFilesEverythingKeepsItsStateAcrossPanes() throws {
        try openFile()
        app.openPane("Chat")
        app.openFilesEverything()
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 60))
        XCTAssertTrue(((element("files.viewer").value as? String) ?? "").contains("Importer"))
    }

    func testEditAndSave() throws {
        try openFile()
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 60))
        typeIntoEditor("// added by hand\n")
        element("files.editor.save").tap()
        XCTAssertTrue(
            app.staticTexts["Saved. Claude reads the change at its next step."].waitForExistence(timeout: 60))
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 60))
        XCTAssertTrue(((element("files.viewer").value as? String) ?? "").contains("added by hand"))
    }

    func testUnsavedTextSurvivesARelaunch() throws {
        try openFile(arguments: ["-yakTestKeepFiles", "fresh"])
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 60))
        typeIntoEditor("// not saved yet\n")
        XCUIDevice.shared.press(.home)
        Thread.sleep(forTimeInterval: 1)
        app.terminate()
        app.launchArguments = app.launchArguments.map { $0 == "fresh" ? "again" : $0 }
        app.launch()
        app.openThread("d0e515ef")
        app.openFilesEverything()
        XCTAssertTrue(element("files.row.src").waitForExistence(timeout: 60))
        element("files.row.src").tap()
        XCTAssertTrue(element("files.row.src/Importer.php").waitForExistence(timeout: 60))
        element("files.row.src/Importer.php").tap()
        XCTAssertTrue(app.staticTexts["Restored the text you had not saved."].waitForExistence(timeout: 60))
        XCTAssertTrue(((app.textViews["files.editor"].value as? String) ?? "").contains("not saved yet"))
    }

    func testALostSaveIsSaved() throws {
        try openFile("files-lost-save")
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 60))
        typeIntoEditor("// kept\n")
        element("files.editor.save").tap()
        XCTAssertTrue(element("files.editor.again").waitForExistence(timeout: 60))
        element("files.editor.again").tap()
        XCTAssertTrue(
            app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Saved'")).firstMatch.waitForExistence(
                timeout: 60))
        XCTAssertEqual(try stub.get("files/puts"), "2")
    }

    func testAConflictKeepsTheText() throws {
        try openFile("files-conflict")
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 60))
        typeIntoEditor("// mine\n")
        element("files.editor.save").tap()
        XCTAssertTrue(element("files.conflict").waitForExistence(timeout: 60))
        element("files.conflict.keepMine").tap()
        XCTAssertTrue(
            app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Saved'")).firstMatch.waitForExistence(
                timeout: 60))
    }

    private func audit() throws {
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, auditBars] issue in
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
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
