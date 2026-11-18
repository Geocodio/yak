import UIKit
import XCTest

/// The quality-bar walkthrough of attachments: composer tiles, the paste button, the upload lines under an unsent
/// message and the attachments on a sent one. One screenshot and one audit per state in `$YAK_SHOT_DIR`. Run it with
/// `bundle exec fastlane walkthrough only:AttachmentWalkthroughTests`.
@MainActor final class AttachmentWalkthroughTests: WalkthroughCase {
    private func openThread() throws {
        if !app.otherElements["drawer"].waitForExistence(timeout: Wait.appear) { app.buttons["threads.button"].tap() }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
    }

    private func typeAndSend(_ text: String) {
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText(text)
        app.buttons["composer.send"].tap()
    }

    private func element(endingWith suffix: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "identifier ENDSWITH %@", suffix)).firstMatch
    }

    func testComposerAttachments() throws {
        try start("chat-attachments", paired: true, attachments: 2)
        try openThread()
        XCTAssertTrue(app.descendants(matching: .any)["composer.attachments"].waitForExistence(timeout: Wait.appear))
        try shootThread("attachments-composer")
    }

    func testPasteButton() throws {
        UIPasteboard.general.image = UIGraphicsImageRenderer(size: CGSize(width: 40, height: 40)).image { context in
            UIColor.systemTeal.setFill()
            context.fill(CGRect(x: 0, y: 0, width: 40, height: 40))
        }
        try start("chat-attachments", paired: true)
        try openThread()
        XCTAssertTrue(app.buttons["composer.pasteImage"].waitForExistence(timeout: Wait.appear))
        try shootThread("attachments-paste")
    }

    func testUploadingLine() throws {
        try start("upload-slow", paired: true, attachments: 1)
        try openThread()
        typeAndSend("Here is the screenshot")
        XCTAssertTrue(element(endingWith: ".upload").waitForExistence(timeout: Wait.firstLoad))
        try shootThread("attachments-uploading")
    }

    func testUploadFailedLine() throws {
        try start(
            "chat-attachments", paired: true, attachments: 1, extraArguments: ["-yakTestUploadFailure", "YES"])
        try openThread()
        typeAndSend("This one fails first")
        XCTAssertTrue(
            app.staticTexts["Not sent. Tap Retry, or it tries again later."].waitForExistence(timeout: Wait.firstLoad))
        try shootThread("attachments-upload-failed")
    }

    func testAttachmentsOnAMessage() throws {
        try start("chat-attachments", paired: true)
        try openThread()
        XCTAssertTrue(element(endingWith: ".attachment.41").waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(element(endingWith: ".attachment.42").waitForExistence(timeout: Wait.appear))
        try shootThread("attachments-message")
    }

    /// A screenshot of a screen with a thread, then the audit without the findings about text in the navigation bar,
    /// whose size the system caps. The Dynamic Type and clipped-text checks run last: they change the text size,
    /// which lays the conversation out again, so every other check reads the screen as it was shot.
    private func shootThread(_ name: String) throws {
        try shoot(name, audits: false)
        let keyboard = keyboardFrame()
        let auditBars = AuditRules.Bars(app)
        let judge: (XCUIAccessibilityAuditIssue) -> Bool = { [app, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            if (issue.element?.debugDescription ?? "").contains("TUIPredictionViewCell") { return true }
            if issue.auditType == .contrast, let element = issue.element, let keyboard,
                keyboard.intersects(element.frame)
            {
                return true
            }
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            XCTFail("audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element")")
            return true
        }
        let resizing: XCUIAccessibilityAuditType = [.dynamicType, .textClipped]
        try app.performAccessibilityAudit(for: XCUIAccessibilityAuditType.all.subtracting(resizing), judge)
        try app.performAccessibilityAudit(for: resizing, judge)
    }
}
