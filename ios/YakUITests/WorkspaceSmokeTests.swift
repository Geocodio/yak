import XCTest

extension XCUIApplication {
    /// Opens a thread from the drawer, which shows first on a phone.
    func openThread(_ id: String) {
        let row = descendants(matching: .any)["drawer.row.thread:\(id)"]
        if !descendants(matching: .any)["drawer.list"].waitForExistence(timeout: 10) { buttons["threads.button"].tap() }
        XCTAssertTrue(descendants(matching: .any)["drawer.list"].waitForExistence(timeout: 10))
        // A list of many threads builds the later rows only after a scroll.
        var scrolls = 0
        while !row.isHittable, scrolls < 6 {
            if row.waitForExistence(timeout: 3), row.isHittable { break }
            swipeUp()
            scrolls += 1
        }
        // One more scroll lifts the row clear of the drawer's search bar at the bottom.
        if scrolls > 0 { swipeUp() }
        XCTAssertTrue(row.exists, "the thread row is not in the drawer")
        row.tap()
    }

    /// Switches to a pane by its name through the pane menu or, where the panes are a row, its button.
    func openPane(_ name: String) {
        let menu = buttons["pane.menu"]
        if menu.waitForExistence(timeout: 5) {
            menu.tap()
            let byIdentifier = descendants(matching: .any)["pane.menu.\(name.lowercased())"]
            let item =
                byIdentifier.waitForExistence(timeout: 3) ? byIdentifier : buttons.matching(identifier: name).firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 5))
            item.tap()
        } else {
            let row = buttons["pane.row.\(name.lowercased())"]
            XCTAssertTrue(row.waitForExistence(timeout: 5))
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
        XCTAssertTrue(output.waitForExistence(timeout: 15))
        waitForValue(output, containing: "stub$")
        try audit()
        output.tap()
        app.typeText("echo hello\n")
        waitForValue(output, containing: "hello")
        try stub.post("terminal/close", "1011")
        XCTAssertTrue(app.descendants(matching: .any)["terminal.banner"].waitForExistence(timeout: 10))
        waitForValue(output, containing: "New shell. The connection dropped at", timeout: 20)
        XCTAssertEqual(try stub.get("terminal/opened"), "2")
    }

    func testTerminalIdleCloseDoesNotReconnect() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Terminal")
        waitForValue(output, containing: "stub$", timeout: 15)
        try stub.post("terminal/close", "4408")
        XCTAssertTrue(
            app.staticTexts["This shell closed after 30 minutes without input."].waitForExistence(timeout: 10))
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

    private func openFile(_ scenario: String = "workspace") throws {
        try launch(scenario: scenario)
        app.openThread("d0e515ef")
        app.openPane("Files")
        XCTAssertTrue(element("files.row.src").waitForExistence(timeout: 15))
        element("files.row.src").tap()
        XCTAssertTrue(element("files.row.src/Importer.php").waitForExistence(timeout: 10))
        element("files.row.src/Importer.php").tap()
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 10))
    }

    private func typeIntoEditor(_ text: String) {
        let editor = app.textViews["files.editor"]
        XCTAssertTrue(editor.waitForExistence(timeout: 5))
        editor.coordinate(withNormalizedOffset: CGVector(dx: 0.6, dy: 0.05)).tap()
        app.typeText(text)
    }

    func testFilesTreeOpensAFolderAndAFile() throws {
        try launch(scenario: "workspace")
        app.openThread("d0e515ef")
        app.openPane("Files")
        XCTAssertTrue(element("files.row.src").waitForExistence(timeout: 15))
        element("files.row.src").tap()
        let file = element("files.row.src/Importer.php")
        XCTAssertTrue(file.waitForExistence(timeout: 10))
        XCTAssertTrue(file.label.contains("modified"))
        try audit()
        file.tap()
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 10))
        try audit()
    }

    func testFilesEverythingKeepsItsStateAcrossPanes() throws {
        try openFile()
        app.openPane("Chat")
        app.openPane("Files")
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 10))
        XCTAssertTrue(((element("files.viewer").value as? String) ?? "").contains("Importer"))
    }

    func testEditAndSave() throws {
        try openFile()
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 10))
        typeIntoEditor("// added by hand\n")
        element("files.editor.save").tap()
        XCTAssertTrue(
            app.staticTexts["Saved. Claude reads the change at its next step."].waitForExistence(timeout: 10))
        XCTAssertTrue(element("files.viewer").waitForExistence(timeout: 10))
        XCTAssertTrue(((element("files.viewer").value as? String) ?? "").contains("added by hand"))
    }

    func testALostSaveIsSaved() throws {
        try openFile("files-lost-save")
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 10))
        typeIntoEditor("// kept\n")
        element("files.editor.save").tap()
        XCTAssertTrue(element("files.editor.again").waitForExistence(timeout: 15))
        element("files.editor.again").tap()
        XCTAssertTrue(
            app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Saved'")).firstMatch.waitForExistence(
                timeout: 10))
        XCTAssertEqual(try stub.get("files/puts"), "2")
    }

    func testAConflictKeepsTheText() throws {
        try openFile("files-conflict")
        element("files.viewer.edit").tap()
        XCTAssertTrue(element("files.editor").waitForExistence(timeout: 10))
        typeIntoEditor("// mine\n")
        element("files.editor.save").tap()
        XCTAssertTrue(element("files.conflict").waitForExistence(timeout: 10))
        element("files.conflict.keepMine").tap()
        XCTAssertTrue(
            app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Saved'")).firstMatch.waitForExistence(
                timeout: 10))
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
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "none")"
            )
            return true
        }
    }
}
