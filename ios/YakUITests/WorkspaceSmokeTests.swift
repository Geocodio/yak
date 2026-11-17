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
        try app.performAccessibilityAudit { [self] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // On a wide screen the audit reports contrast on the thread screen without naming an element.
            if issue.auditType == .contrast, issue.element == nil { return true }
            // A search field is one line by design.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            let screenshot = XCTAttachment(screenshot: app.screenshot())
            screenshot.lifetime = .keepAlways
            screenshot.name = "audit-failure"
            add(screenshot)
            add(XCTAttachment(string: app.debugDescription))
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
