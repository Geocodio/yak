import UIKit
import XCTest

/// What the walkthrough classes share: the stub, the app, launching a scenario and saving a screenshot of a state
/// followed by the accessibility audit. It has no tests of its own.
@MainActor class WalkthroughCase: XCTestCase {
    /// Screens that show the scrolling thread list. A row cut by the bottom bar there is expected; on a message
    /// screen (empty, no results, error) nothing may be cut.
    static let threadListScreens: Set<String> = [
        "drawer", "drawer-everyone", "drawer-offline", "drawer-sub-threads", "drawer-tabs-mine", "drawer-tabs-review",
        "drawer-tabs-everyone", "drawer-tabs-closed", "drawer-tabs-quiet", "drawer-tabs-older", "drawer-tabs-filters",
        "drawer-tabs-menu-open",
    ]

    var stub: StubControl!
    let app = XCUIApplication()
    let environment = ProcessInfo.processInfo.environment

    override func setUp() async throws {
        continueAfterFailure = true
        stub = try StubControl()
        // Both appearances are set explicitly, because a simulator can be left in dark mode.
        XCUIDevice.shared.appearance = environment["YAK_APPEARANCE"] == "dark" ? .dark : .light
        // The orientation is set both ways, because a landscape run leaves the simulator turned.
        XCUIDevice.shared.orientation = environment["YAK_ORIENTATION"] == "landscape" ? .landscapeLeft : .portrait
    }

    func start(
        _ scenario: String, paired: Bool = false, keychainLocked: Bool = false, skipsWebSession: Bool = false,
        attachments: Int = 0, cameraDenied: Bool = false, extraArguments: [String] = []
    ) throws {
        try stub.play(scenario)
        var arguments = ["-yakTestInstance", stub.url]
        if paired { arguments += ["-yakTestToken", "stub-token"] }
        if keychainLocked { arguments += ["-yakTestKeychainLocked", "YES"] }
        if skipsWebSession { arguments += ["-yakTestSkipWebSession", "YES"] }
        if cameraDenied { arguments += ["-yakTestCamera", "denied"] }
        if attachments > 0 { arguments += ["-yakTestAttachments", String(attachments)] }
        // The device appearance alone is not honoured on every iPad run, so the app is also told directly.
        arguments += ["-yakTestAppearance", environment["YAK_APPEARANCE"] == "dark" ? "dark" : "light"]
        if environment["YAK_TEXT_SIZE"] == "largest" {
            arguments += ["-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityXXXL"]
        }
        app.launchArguments = arguments + extraArguments
        app.launch()
    }

    /// Opens the account sheet from the drawer, opening the drawer first when the thread screen shows.
    func openAccount() {
        let account = app.buttons["drawer.account"]
        if !account.waitForExistence(timeout: 10) { app.buttons["threads.button"].tap() }
        XCTAssertTrue(account.waitForExistence(timeout: 10))
        account.tap()
    }

    func label(_ text: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS %@", text)).firstMatch
    }
    /// A screenshot is always in the device's portrait orientation; in landscape it is turned so it reads upright.
    func upright(_ screenshot: XCUIScreenshot) -> Data {
        guard environment["YAK_ORIENTATION"] == "landscape", let image = screenshot.image.cgImage else {
            return screenshot.pngRepresentation
        }
        let turned = UIImage(cgImage: image, scale: screenshot.image.scale, orientation: .left)
        let format = UIGraphicsImageRendererFormat()
        format.scale = turned.scale
        return UIGraphicsImageRenderer(size: turned.size, format: format).pngData { _ in turned.draw(at: .zero) }
    }

    /// The software keyboard's frame, or nil without one. The audit takes the keyboard out of the app's element tree
    /// while it runs, so a rule that needs the keyboard reads this before the audit starts.
    func keyboardFrame() -> CGRect? {
        app.keyboards.firstMatch.exists ? app.keyboards.firstMatch.frame : nil
    }

    /// The frame of the sheet a screen shows, for the screens that show one, or nil. SwiftUI can hand the identifier
    /// to inner elements too, so the largest match is the sheet, and a match that is too small to be one fails the
    /// test instead of excusing findings inside the sheet.
    private func sheetFrame(named name: String) -> CGRect? {
        let identifiers = [
            "model-picker": "model.picker", "camera-denied": "composer.cameraDenied",
            "drawer-tabs-filter-sheet": "drawer.filterSheet", "drawer-tabs-filter-sheet-large": "drawer.filterSheet",
        ]
        guard let identifier = identifiers[name] else { return nil }
        let frames = app.otherElements.matching(identifier: identifier).allElementsBoundByIndex.map(\.frame)
        guard let sheet = frames.max(by: { $0.width * $0.height < $1.width * $1.height }) else { return nil }
        if sheet.width < app.windows.firstMatch.frame.width / 2 || sheet.height < 200 {
            XCTFail("sheet frame for [\(name)] is too small to be the sheet: \(sheet)")
            return nil
        }
        return sheet
    }

    /// A screenshot of a thread screen, then the audit without the findings about text in the navigation bar,
    /// whose size the system caps. The Dynamic Type and clipped-text checks run last: they change the text size,
    /// which lays the conversation out again and moves its rows, so every other check reads the list as it was shot.
    func shootThread(_ name: String) throws {
        try shoot(name, audits: false)
        let keyboard = keyboardFrame()
        let auditBars = AuditRules.Bars(app)
        let judge: (XCUIAccessibilityAuditIssue) -> Bool = { [app, auditBars] issue in
            let bars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, and draws its glass under the threads badge.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                if issue.auditType == .contrast, element.label.wholeMatch(of: /\d+/) != nil { return true }
            }
            // The system keyboard's prediction bar is not ours to label, and what it covers cannot be judged.
            if (issue.element?.debugDescription ?? "").contains("TUIPredictionViewCell") { return true }
            if issue.auditType == .contrast, let element = issue.element, let keyboard,
                keyboard.intersects(element.frame)
            {
                return true
            }
            // On a wide screen the audit reports contrast in the drawer's sidebar without naming an element.
            if issue.auditType == .contrast, issue.element == nil, app.windows.firstMatch.frame.width > 700 {
                return true
            }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            XCTFail(
                "audit [\(name)]: \(issue.compactDescription) | \(issue.element?.debugDescription ?? "no element") | bars \(auditBars.bars.map(\.frame)) window \(auditBars.window)"
            )
            return true
        }
        let resizing: XCUIAccessibilityAuditType = [.dynamicType, .textClipped]
        try app.performAccessibilityAudit(for: XCUIAccessibilityAuditType.all.subtracting(resizing), judge)
        try app.performAccessibilityAudit(for: resizing, judge)
    }

    /// Saves the screenshot once animations settle, then audits the screen. `audits: false` is for screens
    /// that the system covers with its own UI, which the audit cannot reach.
    func shoot(_ name: String, audits: Bool = true) throws {
        Thread.sleep(forTimeInterval: 0.8)
        let directory = try XCTUnwrap(environment["YAK_SHOT_DIR"], "YAK_SHOT_DIR is not set")
        try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
        try upright(XCUIScreen.main.screenshot()).write(to: URL(fileURLWithPath: "\(directory)/\(name).png"))
        // The remove confirmation is the system's own dialog; the audit reports only its fixed text sizes.
        guard audits, !name.hasSuffix("remove-confirmation") else { return }
        let keyboard = keyboardFrame()
        let sheet = sheetFrame(named: name)
        let auditBars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { [self, app, auditBars] issue in
            let description = issue.element?.debugDescription ?? ""
            // The system keyboard's prediction bar is not ours to label.
            if (description + issue.detailedDescription).contains("TUIPredictionViewCell") { return true }
            // A disabled control is dimmed on purpose, and the system's own sheet buttons are not ours to change.
            if issue.auditType == .contrast, description.contains("Disabled") { return true }
            // A bare age ("5h") is a few points wide; on a wide screen the audit measures it against the sidebar's
            // edge instead of its own background, though it is the primary label color on a plain surface.
            if issue.auditType == .contrast, let label = issue.element?.label,
                label.wholeMatch(of: /\d+[mhd]/) != nil
            {
                return true
            }
            // The system caps the size of text in its bars, which holds the thread title.
            if let element = issue.element, element.identifier == "thread.title",
                [.dynamicType, .textClipped].contains(issue.auditType),
                app.navigationBars.allElementsBoundByIndex.contains(where: { $0.frame.contains(element.frame) })
            {
                return true
            }
            // An element entirely outside the screen shows no pixels to measure.
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
            // Text behind a bar is covered by the bar.
            if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
            // The keyboard covers part of a screen that scrolls; what is behind it cannot be judged.
            if issue.auditType == .contrast, let element = issue.element, let keyboard,
                keyboard.intersects(element.frame)
            {
                return true
            }
            // The system dims the screen behind a sheet, so the text outside the sheet reads too light. What the sheet
            // holds is still judged: only an element whose center lies outside the sheet is skipped.
            if issue.auditType == .contrast, let element = issue.element, let sheet,
                !sheet.contains(CGPoint(x: element.frame.midX, y: element.frame.midY))
            {
                return true
            }
            // The Live Activity gallery draws each presentation in a fixed-size frame that mirrors the system's capped
            // sizes, so only text size and clipping findings are excused; contrast and missing labels are judged.
            if name.hasPrefix("live-"), [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            // The audit cannot match some text under the dimming of a sheet to an element.
            if issue.auditType == .elementDetection, issue.element == nil,
                ["signed-in-add-sheet", "pair-again-address-sheet", "model-picker", "camera-denied"].contains(name)
            {
                return true
            }
            // The thread screen under a small sheet is dimmed, and its truncated title and badge have no element the audit can
            // name; the sheet's own content is judged.
            if ["model-picker", "camera-denied"].contains(name), [.contrast, .textClipped].contains(issue.auditType),
                issue.element == nil
            {
                return true
            }
            // Text below the screen on a scrolling page, or a finding without an element, cannot be judged by
            // the pixels shown.
            let screenBottom = app.windows.firstMatch.frame.maxY
            // At the largest text size the long replacement note runs past the screen edge, and the line cut by the
            // edge has no element of its own.
            if issue.auditType == .contrast, issue.element == nil, environment["YAK_TEXT_SIZE"] == "largest",
                name == "link-confirmation-replaces"
            {
                return true
            }
            if issue.auditType == .contrast, let element = issue.element, element.frame.maxY > screenBottom {
                return true
            }
            // The Cancel or Close in a navigation bar is the toolbar button, which is dimmed under a sheet's dimming and
            // sized by the system; the Cancel buttons in the page are judged.
            if let element = issue.element, ["Cancel", "Close"].contains(element.label),
                [.contrast, .dynamicType].contains(issue.auditType),
                app.navigationBars.allElementsBoundByIndex.contains(where: { $0.frame.contains(element.frame) })
            {
                return true
            }
            // In landscape on a phone the split view's detail column runs past the screen edge while the sidebar is
            // open, so the placeholder's centered text sits against the edge; the real thread screen replaces it.
            if issue.auditType == .textClipped, environment["YAK_ORIENTATION"] == "landscape",
                ["Pick a thread from the list.", "New thread"].contains(issue.element?.label ?? "")
            {
                return true
            }
            // In landscape the list is short, so a row is cut by the bottom bar; the audit finds that cut text but
            // cannot name its element. Only the screens that show the thread list are excused, never a message screen.
            if issue.auditType == .contrast, issue.element == nil, environment["YAK_ORIENTATION"] == "landscape",
                Self.threadListScreens.contains(name)
            {
                return true
            }
            // At the largest text size a list scrolls under the bottom bar, and the audit finds the text that the
            // bar covers. Only these screens are excused, and only a finding without an element or one whose element
            // meets the bar.
            if issue.auditType == .contrast, environment["YAK_TEXT_SIZE"] == "largest",
                Self.threadListScreens.contains(name),
                issue.element.map({ $0.frame.maxY > app.textFields["drawer.search"].frame.minY - 8 }) ?? true
            {
                return true
            }
            // The address field is one line by design: an address never wraps.
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            XCTFail(
                "audit [\(name)]: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
            )
            return true
        }
    }
}
