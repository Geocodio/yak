import XCTest

/// The commands menu above the message field, against the stub's `slash-*` scenarios.
@MainActor final class SlashCommandSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    /// Plays the scenario, opens the core thread and puts the keyboard in the message field.
    private func openField(scenario: String) throws -> XCUIElement {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: 5) { threads.tap() }
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        return field
    }

    private var menu: XCUIElement { app.descendants(matching: .any)["composer.slash"] }

    private func row(_ name: String) -> XCUIElement {
        app.descendants(matching: .any)["composer.slash.row.\(name)"]
    }

    private func postedMessages() -> String { (try? stub.get("slash/messages")) ?? "" }

    func testTypingASlashShowsTheCommandsAndTypingFiltersThem() throws {
        let field = try openField(scenario: "slash-commands")
        field.typeText("/")
        XCTAssertTrue(menu.waitForExistence(timeout: Wait.appear))
        for name in ["write-tests", "review-pr", "compact", "memory", "clear"] {
            XCTAssertTrue(row(name).exists, name)
        }
        XCTAssertTrue(app.staticTexts["Skills"].exists || app.staticTexts["SKILLS"].exists)
        field.typeText("re")
        XCTAssertTrue(row("review-pr").waitForExistence(timeout: Wait.appear))
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: row("write-tests"))], timeout: 15)
        XCTAssertFalse(row("compact").exists)
        XCTAssertTrue(row("review").exists && row("refactor").exists)
        try auditThreadScreen(app)
    }

    func testATapFillsTheCommandAndNothingIsSentUntilSend() throws {
        let field = try openField(scenario: "slash-commands")
        field.typeText("/re")
        XCTAssertTrue(row("review-pr").waitForExistence(timeout: Wait.appear))
        row("review-pr").tap()
        let filled = NSPredicate(format: "value == %@", "/review-pr ")
        wait(for: [XCTNSPredicateExpectation(predicate: filled, object: field)], timeout: 15)
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: menu)], timeout: 15)
        XCTAssertEqual(postedMessages(), "", "a tap on a command sends nothing")
        field.typeText("42")
        let send = app.buttons["composer.send"]
        XCTAssertTrue(send.waitForExistence(timeout: Wait.appear))
        send.tap()
        let sent = NSPredicate { _, _ in self.postedMessages() == "/review-pr 42" }
        wait(for: [XCTNSPredicateExpectation(predicate: sent, object: nil)], timeout: 30)
        // Once: a second post would show as a second line.
        Thread.sleep(forTimeInterval: 1)
        XCTAssertEqual(postedMessages(), "/review-pr 42")
    }

    func testAnOlderServerShowsNoMenuAndTheSlashIsText() throws {
        let field = try openField(scenario: "slash-older")
        field.typeText("/re")
        let value = NSPredicate(format: "value == %@", "/re")
        wait(for: [XCTNSPredicateExpectation(predicate: value, object: field)], timeout: 15)
        Thread.sleep(forTimeInterval: 1)
        XCTAssertFalse(menu.exists)
    }

    func testANameWithNoMatchSaysTheTextGoesToClaude() throws {
        let field = try openField(scenario: "slash-none")
        field.typeText("/")
        Thread.sleep(forTimeInterval: 1)
        XCTAssertFalse(menu.exists, "a bare slash with no commands opens nothing")
        field.typeText("xyz")
        let notice = app.descendants(matching: .any)["composer.slash.noMatch"]
        XCTAssertTrue(notice.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(notice.label.contains("No command named /xyz"), notice.label)
        try auditThreadScreen(app)
    }

    func testTheHardwareKeyboardMovesTheHighlight() throws {
        let field = try openField(scenario: "slash-commands")
        field.typeText("/")
        XCTAssertTrue(menu.waitForExistence(timeout: Wait.appear))
        XCTAssertTrue(row("write-tests").isSelected)
        app.typeKey(.downArrow, modifierFlags: [])
        let moved = NSPredicate(format: "selected == true")
        wait(for: [XCTNSPredicateExpectation(predicate: moved, object: row("review-pr"))], timeout: 15)
        app.typeKey(.upArrow, modifierFlags: [])
        wait(for: [XCTNSPredicateExpectation(predicate: moved, object: row("write-tests"))], timeout: 15)
    }

    /// With no menu open Return is a line break.
    func testReturnWithNoMenuIsALineBreak() throws {
        let field = try openField(scenario: "slash-commands")
        field.typeText("hello")
        XCTAssertFalse(menu.exists)
        field.typeText("\n")
        let broken = NSPredicate(format: "value == %@", "hello\n")
        wait(for: [XCTNSPredicateExpectation(predicate: broken, object: field)], timeout: 15)
        XCTAssertEqual(postedMessages(), "")
    }
}
