import XCTest

/// Opening the Preview pane of the stub's first thread, shared by the smoke tests and the walkthrough.
@MainActor struct PreviewScreen {
    let app: XCUIApplication

    func waitForDrawer() {
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(app.otherElements["drawer"].waitForExistence(timeout: Wait.appear))
    }

    /// Opens the thread from the drawer and shows its Preview pane.
    func open() {
        waitForDrawer()
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.appear))
        // A scenario with a question opens its thread by itself, so the drawer is already away.
        if !app.buttons["pane.menu"].exists { row.tap() }
        let menu = app.buttons["pane.menu"]
        if menu.waitForExistence(timeout: 15) {
            menu.tap()
            let item = app.buttons["Preview"].firstMatch
            XCTAssertTrue(item.waitForExistence(timeout: Wait.appear))
            item.tap()
        } else if app.buttons["pane.row.preview"].exists {
            app.buttons["pane.row.preview"].tap()
        } else if app.buttons["pane.strip.preview"].exists {
            app.buttons["pane.strip.preview"].tap()
        }
        XCTAssertTrue(app.descendants(matching: .any)["preview.pane"].waitForExistence(timeout: Wait.appear))
    }
}
