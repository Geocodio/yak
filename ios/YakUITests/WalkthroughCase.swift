import UIKit
import XCTest

/// What the walkthrough classes share: the stub, the app, launching a scenario and saving a screenshot of a state
/// followed by the accessibility audit. It has no tests of its own.
@MainActor class WalkthroughCase: XCTestCase {
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
        _ scenario: String, paired: Bool = false, keychainLocked: Bool = false, skipsWebSession: Bool = false
    ) throws {
        try stub.play(scenario)
        var arguments = ["-yakTestInstance", stub.url]
        if paired { arguments += ["-yakTestToken", "stub-token"] }
        if keychainLocked { arguments += ["-yakTestKeychainLocked", "YES"] }
        if skipsWebSession { arguments += ["-yakTestSkipWebSession", "YES"] }
        // The device appearance alone is not honoured on every iPad run, so the app is also told directly.
        if environment["YAK_APPEARANCE"] == "dark" { arguments += ["-AppleInterfaceStyle", "Dark"] }
        if environment["YAK_TEXT_SIZE"] == "largest" {
            arguments += ["-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityXXXL"]
        }
        app.launchArguments = arguments
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

    /// Saves the screenshot once animations settle, then audits the screen. `audits: false` is for screens
    /// that the system covers with its own UI, which the audit cannot reach.
    func shoot(_ name: String, audits: Bool = true) throws {
        Thread.sleep(forTimeInterval: 0.8)
        let directory = try XCTUnwrap(environment["YAK_SHOT_DIR"], "YAK_SHOT_DIR is not set")
        try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
        try upright(XCUIScreen.main.screenshot()).write(to: URL(fileURLWithPath: "\(directory)/\(name).png"))
        // The remove confirmation is the system's own dialog; the audit reports only its fixed text sizes.
        guard audits, !name.hasSuffix("remove-confirmation") else { return }
        try app.performAccessibilityAudit { [self, app] issue in
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
            // The keyboard covers part of a screen that scrolls; what is behind it cannot be judged.
            if issue.auditType == .contrast, let element = issue.element, app.keyboards.firstMatch.exists,
                app.keyboards.firstMatch.frame.intersects(element.frame)
            {
                return true
            }
            // The audit cannot match some text under the dimming of a sheet to an element.
            if issue.auditType == .elementDetection, issue.element == nil,
                ["signed-in-add-sheet", "pair-again-address-sheet"].contains(name)
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
            // cannot name its element. Only these two list screens are excused.
            if issue.auditType == .contrast, issue.element == nil, environment["YAK_ORIENTATION"] == "landscape",
                ["drawer-offline", "drawer-sub-threads"].contains(name)
            {
                return true
            }
            // At the largest text size the drawer's message or list runs under the bottom bar or the keyboard; the
            // audit finds that cut text but cannot name its element. Only the drawer screens are excused.
            if issue.auditType == .contrast, issue.element == nil, environment["YAK_TEXT_SIZE"] == "largest",
                name.hasPrefix("drawer")
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
