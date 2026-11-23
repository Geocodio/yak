import XCTest

/// The quality-bar walkthrough of the file viewer: one screenshot per state in `$YAK_SHOT_DIR`. The report is the
/// stub's test report. Run it with `bundle exec fastlane walkthrough only:FileViewerWalkthroughTests`.
@MainActor final class FileViewerWalkthroughTests: WalkthroughCase {
    private func openThreadWithReport() throws {
        try start("embed-basic", paired: true)
        if !app.otherElements["drawer"].waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(app.staticTexts["Ready"].waitForExistence(timeout: Wait.firstLoad))
    }

    private func openReport() {
        let link = app.links["Open report"]
        XCTAssertTrue(link.waitForExistence(timeout: Wait.appear))
        link.tap()
    }

    func testFileViewer() throws {
        try openThreadWithReport()
        openReport()
        XCTAssertTrue(
            app.staticTexts["Signups grew by twelve percent this week."].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer", audits: false)
        app.buttons["fileViewer.menu"].tap()
        XCTAssertTrue(app.buttons["Open in Safari"].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-menu", audits: false)
        app.buttons["Copy link"].tap()
        // The toast stays for under two seconds, so the picture is taken at once.
        let shot = XCUIScreen.main.screenshot()
        let directory = try XCTUnwrap(environment["YAK_SHOT_DIR"])
        try shot.pngRepresentation.write(to: URL(fileURLWithPath: "\(directory)/file-viewer-copied.png"))
    }

    func testFileViewerLoadingAndSlow() throws {
        try openThreadWithReport()
        try stub.post("embed/file/slow", "")
        openReport()
        XCTAssertTrue(app.descendants(matching: .any)["thread.loading"].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-loading", audits: false)
        XCTAssertTrue(app.descendants(matching: .any)["thread.slow"].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-slow", audits: false)
    }

    func testFileViewerNotAvailable() throws {
        try openThreadWithReport()
        try stub.post("embed/file/missing", "")
        openReport()
        XCTAssertTrue(app.staticTexts["This file is not available."].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-unavailable", audits: false)
    }

    func testFileViewerServerError() throws {
        try openThreadWithReport()
        try stub.post("embed/file/broken", "")
        openReport()
        XCTAssertTrue(app.descendants(matching: .any)["thread.error"].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-error", audits: false)
    }

    func testFileViewerOffline() throws {
        try openThreadWithReport()
        try stub.setOnline(false)
        XCTAssertTrue(app.descendants(matching: .any)["thread.web.offlineLine"].waitForExistence(timeout: Wait.appear))
        openReport()
        XCTAssertTrue(app.staticTexts["You are offline"].waitForExistence(timeout: Wait.appear))
        try shoot("file-viewer-offline", audits: false)
    }
}
