import XCTest

/// Opening the Preview pane of the stub's first thread, shared by the smoke tests and the walkthrough.
@MainActor struct PreviewScreen {
    let app: XCUIApplication

    func waitForDrawer() {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(app.otherElements["drawer"].waitForExistence(timeout: 10))
    }

    /// Opens the thread from the drawer and shows its Preview pane.
    func open() {
        waitForDrawer()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: 10))
        row.tap()
        let menu = app.buttons["pane.menu"]
        if menu.waitForExistence(timeout: 15) {
            menu.tap()
            let item = app.buttons["Preview"].firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: 5))
            item.tap()
        } else if app.buttons["pane.row.preview"].exists {
            app.buttons["pane.row.preview"].tap()
        } else if app.buttons["pane.strip.preview"].exists {
            app.buttons["pane.strip.preview"].tap()
        }
        XCTAssertTrue(app.descendants(matching: .any)["preview.pane"].waitForExistence(timeout: 15))
    }
}
