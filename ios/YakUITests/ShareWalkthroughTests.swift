import XCTest

/// The quality-bar walkthrough of the share sheet, one screenshot and audit per state, through the in-app host that
/// uses fake shared items. Run it with `bundle exec fastlane walkthrough only:ShareWalkthroughTests`.
@MainActor final class ShareWalkthroughTests: WalkthroughCase {
    private func share(_ name: String, waitFor identifier: String = "share.send") throws {
        try start("paired", paired: true, extraArguments: ["-yakTestShare", name])
        XCTAssertTrue(app.descendants(matching: .any)[identifier].waitForExistence(timeout: Wait.firstLoad))
    }

    func testShareReady() throws {
        try share("ready", waitFor: "share.repository")
        XCTAssertTrue(app.descendants(matching: .any)["share.preview"].waitForExistence(timeout: Wait.appear))
        try shoot("share-ready")
    }

    func testShareLastThread() throws {
        try share("last-thread")
        try shoot("share-last-thread")
    }

    func testSharePrivateLastThread() throws {
        try share("private-last-thread")
        XCTAssertTrue(app.staticTexts["A private thread"].waitForExistence(timeout: Wait.appear))
        try shoot("share-private-last-thread")
    }

    func testShareNoInstance() throws {
        try share("no-instance", waitFor: "share.notice")
        try shoot("share-no-instance")
    }

    func testShareSignedOut() throws {
        try share("signed-out", waitFor: "share.notice")
        try shoot("share-signed-out")
    }

    func testShareTooMany() throws {
        try share("too-many", waitFor: "share.notice")
        try shoot("share-too-many")
    }

    func testSharePreparing() throws {
        try share("preparing-slow")
        try shoot("share-preparing")
    }

    func testShareNoContextOffline() throws {
        try share("no-context-offline", waitFor: "share.notice")
        try shoot("share-no-context-offline")
    }

    func testShareRepositoryNotReady() throws {
        try share("repository-not-ready", waitFor: "share.notice")
        try shoot("share-repository-not-ready")
    }

    func testShareSaving() throws {
        try share("saving")
        try shoot("share-saving")
    }

    func testShareFailed() throws {
        try share("failed")
        let line = app.staticTexts["This could not be saved for sending. Try again."]
        XCTAssertTrue(line.waitForExistence(timeout: Wait.appear))
        try shoot("share-failed")
    }
}
