import UIKit
import XCTest

/// The quality-bar walkthrough: reaches every state of every screen against the stub server and saves a
/// screenshot of each to `$YAK_SHOT_DIR/<state>.png`, then runs the accessibility audit on it. It is not part
/// of the smoke lane; run it with `bundle exec fastlane walkthrough device:"iPhone 18 Pro" appearance:dark`.
/// `YAK_APPEARANCE` (light or dark), `YAK_TEXT_SIZE` (largest) and `YAK_ORIENTATION` (landscape) pick the variant.
@MainActor final class WalkthroughTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()
    private let environment = ProcessInfo.processInfo.environment

    override func setUp() async throws {
        continueAfterFailure = true
        stub = try StubControl()
        // Both appearances are set explicitly, because a simulator can be left in dark mode.
        XCUIDevice.shared.appearance = environment["YAK_APPEARANCE"] == "dark" ? .dark : .light
        if environment["YAK_ORIENTATION"] == "landscape" { XCUIDevice.shared.orientation = .landscapeLeft }
    }

    // MARK: Connect and scanner

    func testConnect() throws {
        try start("not-yak")
        XCTAssertTrue(app.staticTexts["Connect to your Yak"].waitForExistence(timeout: 10))
        try shoot("connect")
        app.buttons["Scan a code"].tap()
        XCTAssertTrue(app.staticTexts["The camera is not available"].waitForExistence(timeout: 5))
        try shoot("scanner-without-camera")
    }

    // MARK: Typed address

    func testAddressEmpty() throws {
        try start("paired")
        try openAddress()
        try shoot("address-empty")
    }

    func testAddressFoundAndChecking() throws {
        try start("paired")
        try openAddress()
        try type(stub.address)
        XCTAssertTrue(label("Yak found").waitForExistence(timeout: 10))
        try shoot("address-found")
        try stub.play("slow")
        try submit()
        XCTAssertTrue(app.buttons["Checking the address"].waitForExistence(timeout: 5))
        try shoot("address-checking")
    }

    func testAddressCheckingSlowly() throws {
        try start("paired")
        try openAddress()
        try type(stub.address)
        XCTAssertTrue(label("Yak found").waitForExistence(timeout: 10))
        try stub.play("slower")
        try submit()
        XCTAssertTrue(label("The connection is slow").waitForExistence(timeout: 12))
        try shoot("address-checking-slow")
    }

    func testAddressWaitingForApproval() throws {
        // Test mode keeps the system web sheet closed, so the waiting screen itself can be audited.
        try start("approval-pending", skipsWebSession: true)
        try openAddress()
        try type(stub.address)
        try submit()
        XCTAssertTrue(label("Waiting for you to approve").waitForExistence(timeout: 10))
        try shoot("sign-in-wait")
    }

    func testAddressWaitingWithSystemSheet() throws {
        try start("approval-pending")
        try openAddress()
        try type(stub.address)
        try submit()
        XCTAssertTrue(label("Waiting for you to approve").waitForExistence(timeout: 10))
        // The system's own prompt covers the screen; this is how the person sees it.
        try shoot("sign-in-wait-system-prompt", audits: false)
        try continuePastSystemPrompt()
        // The web sign-in sheet is open over the waiting screen; the sheet is the system's, so it is not audited.
        try shoot("sign-in-wait-sheet", audits: false)
        let sheet = XCUIApplication(bundleIdentifier: "com.apple.SafariViewService")
        let close = sheet.buttons.matching(NSPredicate(format: "label IN {'Close', 'Done', 'Cancel'}")).firstMatch
        XCTAssertTrue(close.waitForExistence(timeout: 5), "no close button on the sign-in sheet")
        close.tap()
        // A closed sheet cancels the sign-in and leaves the address screen, ready to try again.
        XCTAssertTrue(app.buttons["Continue to sign in"].waitForExistence(timeout: 10))
        try shoot("sign-in-cancelled")
    }

    func testAddressNoteForNotYak() throws {
        try start("not-yak")
        try openAddress()
        try type(stub.address)
        XCTAssertTrue(label("No Yak at this address").waitForExistence(timeout: 10))
        try shoot("address-note-notYak")
    }

    func testAddressOffline() throws {
        try start("paired")
        try openAddress()
        try type(stub.address)
        try stub.setOnline(false)
        XCTAssertTrue(label("You are offline").waitForExistence(timeout: 15))
        try shoot("address-offline")
    }

    func testAddressProblemInvalid() throws {
        try start("paired")
        try openAddress()
        try type("not an address")
        try submit()
        try expectProblem("That is not an address", shot: "address-problem-invalid")
    }

    func testAddressProblemNotYak() throws { try addressProblem("not-yak", "No Yak at this address") }
    func testAddressProblemUnreachable() throws { try addressProblem("down", "Cannot reach the server") }
    func testAddressProblemServerTooOld() throws { try addressProblem("server-too-old", "This Yak needs an update") }
    func testAddressProblemAppTooOld() throws { try addressProblem("server-too-new", "The app needs an update") }
    func testAddressProblemDenied() throws { try addressProblem("approval-denied", "Sign-in was declined") }
    func testAddressProblemExpired() throws { try addressProblem("approval-expired", "The sign-in timed out") }
    func testAddressProblemBroken() throws { try addressProblem("broken", "Pairing did not finish") }

    // MARK: Pairing link

    func testLinkChecking() throws {
        try start("slow")
        openLink()
        XCTAssertTrue(label("Pairing with your Yak").waitForExistence(timeout: 5))
        try shoot("link-checking")
    }

    func testLinkCheckingSlowly() throws {
        try start("slower")
        openLink()
        XCTAssertTrue(label("The connection is slow").waitForExistence(timeout: 12))
        try shoot("link-checking-slow")
    }

    func testLinkConfirmation() throws {
        try start("paired")
        try openLinkAndWaitForConfirmation()
        try shoot("link-confirmation")
    }

    func testLinkConfirmationReplacesTheSavedInstance() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as").waitForExistence(timeout: 10))
        try openLinkAndWaitForConfirmation()
        XCTAssertTrue(label("replaces the instance").exists)
        try shoot("link-confirmation-replaces")
    }

    func testLinkOverTheAddSheet() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as").waitForExistence(timeout: 10))
        app.buttons["Add an instance"].tap()
        XCTAssertTrue(app.buttons["Scan a code"].waitForExistence(timeout: 5))
        try openLinkAndWaitForConfirmation()
        try shoot("link-over-add-sheet")
    }

    func testLinkOverSignedIn() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as").waitForExistence(timeout: 10))
        try openLinkAndWaitForConfirmation()
        try shoot("link-over-signed-in")
    }

    func testLinkOverPairAgain() throws {
        try start("revoked", paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: 10))
        try openLinkAndWaitForConfirmation()
        try shoot("link-over-pair-again")
    }

    func testLinkOverPairAgainAddressSheet() throws {
        try start("revoked", paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: 10))
        app.buttons["Pair again"].tap()
        XCTAssertTrue(app.textFields.firstMatch.waitForExistence(timeout: 5))
        try openLinkAndWaitForConfirmation()
        try shoot("link-over-pair-again-address-sheet")
    }

    func testLinkRedeeming() throws {
        try start("paired")
        try openLinkAndWaitForConfirmation()
        try stub.play("slow")
        pairButton.tap()
        XCTAssertTrue(app.buttons["Pairing"].waitForExistence(timeout: 5))
        try shoot("link-redeeming")
    }

    func testLinkRedeemingSlowly() throws {
        try start("paired")
        try openLinkAndWaitForConfirmation()
        try stub.play("slower")
        pairButton.tap()
        let giveUp = app.buttons["Stop waiting"]
        XCTAssertTrue(giveUp.waitForExistence(timeout: 12))
        try shoot("link-redeeming-slow")
        giveUp.tap()
        try expectProblem("This code may already be used", shot: "link-problem-codeMayBeUsed")
    }

    func testLinkProblemCodeExpired() throws { try linkRedeemProblem("code-expired", "The code expired") }
    func testLinkProblemCodeUsed() throws { try linkRedeemProblem("code-used", "The code did not work") }
    func testLinkProblemUnreachable() throws { try linkCheckProblem("down", "Cannot reach the server") }
    func testLinkProblemNotYak() throws { try linkCheckProblem("not-yak", "No Yak at this address") }
    func testLinkProblemServerTooOld() throws { try linkCheckProblem("server-too-old", "This Yak needs an update") }
    func testLinkProblemAppTooOld() throws { try linkCheckProblem("server-too-new", "The app needs an update") }
    func testLinkProblemBroken() throws { try linkCheckProblem("broken", "Pairing did not finish") }

    // MARK: Signed in

    func testSignedIn() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as Test Person").waitForExistence(timeout: 10))
        try shoot("signed-in")
        app.buttons["Add an instance"].tap()
        XCTAssertTrue(app.buttons["Scan a code"].waitForExistence(timeout: 5))
        try shoot("signed-in-add-sheet")
    }

    func testSignedInRemoveConfirmation() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as").waitForExistence(timeout: 10))
        app.buttons["Remove this instance"].tap()
        XCTAssertTrue(app.buttons["Remove"].waitForExistence(timeout: 5))
        try shoot("signed-in-remove-confirmation")
    }

    func testSignedInRefreshing() throws {
        try start("slow", paired: true)
        XCTAssertTrue(label("Refreshing").waitForExistence(timeout: 5))
        try shoot("signed-in-refreshing")
    }

    func testSignedInRefreshingSlowly() throws {
        try start("slower", paired: true)
        XCTAssertTrue(label("The connection is slow").waitForExistence(timeout: 12))
        try shoot("signed-in-refreshing-slow")
    }

    func testSignedInOffline() throws {
        try start("paired", paired: true)
        XCTAssertTrue(label("Signed in as").waitForExistence(timeout: 10))
        try stub.setOnline(false)
        XCTAssertTrue(label("You are offline").waitForExistence(timeout: 15))
        try shoot("signed-in-offline")
    }

    func testSignedInUnreachable() throws { try signedInProblem("down", "Could not reach", "signed-in-unreachable") }
    func testSignedInAppTooOld() throws {
        try signedInProblem("server-too-new", "This version of the app is too old", "signed-in-app-too-old")
    }
    func testSignedInServerTooOld() throws {
        try signedInProblem("server-too-old", "Your Yak is too old", "signed-in-server-too-old")
    }
    func testSignedInFailed() throws {
        try signedInProblem("broken", "Your Yak answered with an error", "signed-in-failed")
    }

    func testTokenUnreadable() throws {
        try start("paired", paired: true, keychainLocked: true)
        XCTAssertTrue(label("cannot read its saved sign-in").waitForExistence(timeout: 10))
        try shoot("token-unreadable")
    }

    // MARK: Pair again

    func testPairAgain() throws {
        try start("revoked", paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: 10))
        try shoot("pair-again")
        app.buttons["Pair again"].tap()
        XCTAssertTrue(app.textFields.firstMatch.waitForExistence(timeout: 5))
        try shoot("pair-again-address-sheet")
    }

    func testPairAgainRemoveConfirmation() throws {
        try start("revoked", paired: true)
        XCTAssertTrue(app.buttons["Pair again"].waitForExistence(timeout: 10))
        app.buttons["Remove this instance"].tap()
        XCTAssertTrue(app.buttons["Remove"].waitForExistence(timeout: 5))
        try shoot("pair-again-remove-confirmation")
    }

    // MARK: Flows

    private func addressProblem(_ scenario: String, _ title: String) throws {
        try start("paired")
        try openAddress()
        try type(stub.address)
        try stub.play(scenario)
        try submit()
        if scenario.hasPrefix("approval") { try continuePastSystemPrompt() }
        try expectProblem(title, shot: "address-problem-\(scenario)")
    }

    private func linkCheckProblem(_ scenario: String, _ title: String) throws {
        try start(scenario)
        openLink()
        XCTAssertTrue(app.staticTexts[title].waitForExistence(timeout: 12))
        // On a wide screen the covered screen can stay in the query results; a person cannot reach it.
        XCTAssertFalse(app.staticTexts["Connect to your Yak"].isHittable, "the screen behind the overlay is reachable")
        try expectProblem(title, shot: "link-problem-\(scenario)")
    }

    private func linkRedeemProblem(_ scenario: String, _ title: String) throws {
        try start(scenario)
        try openLinkAndWaitForConfirmation()
        pairButton.tap()
        try expectProblem(title, shot: "link-problem-\(scenario)")
    }

    private func signedInProblem(_ scenario: String, _ text: String, _ shot: String) throws {
        try start(scenario, paired: true)
        XCTAssertTrue(label(text).waitForExistence(timeout: 15))
        try shoot(shot)
    }

    private func expectProblem(_ title: String, shot: String) throws {
        XCTAssertTrue(app.staticTexts[title].waitForExistence(timeout: 12), "no problem screen titled \(title)")
        try shoot(shot)
    }

    private var pairButton: XCUIElement {
        app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Pair with'")).firstMatch
    }

    /// Opens the link the way the system does, in the running app. `XCUIApplication.open` would relaunch the
    /// app, which loses the screen the link is meant to arrive over.
    private func openLink() { XCUIDevice.shared.system.open(stub.pairingLink) }

    private func openLinkAndWaitForConfirmation() throws {
        openLink()
        XCTAssertTrue(pairButton.waitForExistence(timeout: 10))
    }

    // MARK: Helpers

    private func start(
        _ scenario: String, paired: Bool = false, keychainLocked: Bool = false, skipsWebSession: Bool = false
    ) throws {
        try stub.play(scenario)
        var arguments = ["-yakTestInstance", stub.url]
        if paired { arguments += ["-yakTestToken", "stub-token"] }
        if keychainLocked { arguments += ["-yakTestKeychainLocked", "YES"] }
        if skipsWebSession { arguments += ["-yakTestSkipWebSession", "YES"] }
        if environment["YAK_TEXT_SIZE"] == "largest" {
            arguments += ["-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityXXXL"]
        }
        app.launchArguments = arguments
        app.launch()
    }

    private func label(_ text: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS %@", text)).firstMatch
    }

    private func openAddress() throws {
        let button = app.buttons["Enter an address"]
        XCTAssertTrue(button.waitForExistence(timeout: 10))
        button.tap()
        XCTAssertTrue(app.textFields.firstMatch.waitForExistence(timeout: 5))
    }

    private func type(_ text: String) throws {
        let field = app.textFields.firstMatch
        field.tap()
        field.typeText(text)
    }

    /// Sends the return key (labelled Go) to the field, so the walkthrough does not depend on where the
    /// keyboard puts its keys or on the button being visible.
    private func submit() throws {
        app.textFields.firstMatch.typeText("\n")
    }

    /// Taps Continue on the system's "wants to sign in" prompt, which opens the sign-in sheet.
    private func continuePastSystemPrompt() throws {
        // The prompt belongs to the system, not to the app, so it is found through SpringBoard.
        let alert = XCUIApplication(bundleIdentifier: "com.apple.springboard").alerts.firstMatch
        let continueButton = alert.buttons["Continue"]
        XCTAssertTrue(alert.waitForExistence(timeout: 5), "no sign-in prompt")
        continueButton.tap()
        XCTAssertTrue(alert.waitForNonExistence(timeout: 5), "the sign-in prompt is still up")
        Thread.sleep(forTimeInterval: 1)
    }

    /// A screenshot is always in the device's portrait orientation; in landscape it is turned so it reads upright.
    private func upright(_ screenshot: XCUIScreenshot) -> Data {
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
    private func shoot(_ name: String, audits: Bool = true) throws {
        Thread.sleep(forTimeInterval: 0.8)
        let directory = try XCTUnwrap(environment["YAK_SHOT_DIR"], "YAK_SHOT_DIR is not set")
        try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
        try upright(XCUIScreen.main.screenshot()).write(to: URL(fileURLWithPath: "\(directory)/\(name).png"))
        // The remove confirmation is the system's own dialog; the audit reports only its fixed text sizes.
        guard audits, !name.hasSuffix("remove-confirmation") else { return }
        try app.performAccessibilityAudit { [self, app] issue in
            let description = issue.element?.debugDescription ?? ""
            // A disabled control is dimmed on purpose, and the system's own sheet buttons are not ours to change.
            if issue.auditType == .contrast, description.contains("Disabled") { return true }
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
            // The Cancel in a navigation bar is the toolbar button, which is dimmed under a sheet's dimming and
            // sized by the system; the Cancel buttons in the page are judged.
            if let element = issue.element, element.label == "Cancel",
                [.contrast, .dynamicType].contains(issue.auditType),
                app.navigationBars.allElementsBoundByIndex.contains(where: { $0.frame.contains(element.frame) })
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
