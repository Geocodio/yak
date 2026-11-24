import XCTest

/// Smoke tests of attachments that go through the background upload session, against the stub server.
@MainActor final class AttachmentSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String, arguments: [String] = []) throws {
        try stub.play(scenario)
        app.launchArguments =
            ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token", "-yakTestAttachments", "1"] + arguments
        app.launchRetryingOnce()
    }

    private func openThreadAndSend(_ text: String) {
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: Wait.appear) { threads.tap() }
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let field = app.textFields["composer.field"]
        XCTAssertTrue(field.waitForExistence(timeout: Wait.appear))
        field.tap()
        field.typeText(text)
        let send = app.buttons["composer.send"]
        XCTAssertTrue(send.waitForExistence(timeout: Wait.appear))
        send.tap()
    }

    /// The page's own row for an unsent message ("<text> (<status>)"), found by its text.
    private func unsentRow(_ text: String) -> XCUIElement {
        app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "\(text) (")).firstMatch
    }

    private func upload(waitingFor timeout: TimeInterval) -> StubUpload? {
        let deadline = Date().addingTimeInterval(timeout)
        while Date() < deadline {
            if let upload = stub.lastUpload() { return upload }
            RunLoop.current.run(until: Date().addingTimeInterval(0.5))
        }
        return nil
    }

    func testAPhotoIsSentInTheBackground() throws {
        try launch(scenario: "chat-attachments")
        openThreadAndSend("Here is the screenshot")
        let upload = try XCTUnwrap(upload(waitingFor: Wait.firstLoad), "the stub never took the upload")
        XCTAssertEqual(upload.files.count, 1)
        XCTAssertEqual(upload.fields["send_key"]?.count, 1)
        // The unsent message the page lists leaves once the server took it.
        let gone = NSPredicate(format: "exists == false")
        wait(
            for: [XCTNSPredicateExpectation(predicate: gone, object: unsentRow("Here is the screenshot"))],
            timeout: Wait.firstLoad)
    }

    func testAFailedUploadGoesAgainOnTheNextLaunch() throws {
        let runID = UUID().uuidString
        let keep = ["-yakTestKeepFiles", runID]
        try launch(scenario: "chat-attachments", arguments: keep + ["-yakTestUploadFailure", "YES"])
        openThreadAndSend("This one fails first")
        // The app reports the first upload as failed in transport; the stub has taken it all the same.
        _ = try XCTUnwrap(upload(waitingFor: Wait.firstLoad), "the stub never took the upload")
        let instance = app.descendants(matching: .any)["testMode.instanceID"]
        XCTAssertTrue(instance.waitForExistence(timeout: Wait.appear))
        let instanceID = try XCTUnwrap(instance.value as? String)
        XCTAssertFalse(instanceID.isEmpty)
        let sendKey = try XCTUnwrap(stub.lastUpload()?.fields["send_key"]?.first)
        app.terminate()
        // The stub plays no scenario again: the same send key now succeeds.
        app.launchArguments =
            ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"] + keep
        app.launchRetryingOnce()
        let sameInstance = app.descendants(matching: .any)["testMode.instanceID"]
        XCTAssertTrue(sameInstance.waitForExistence(timeout: Wait.appear))
        XCTAssertEqual(sameInstance.value as? String, instanceID)
        let unsent = unsentRow("This one fails first")
        _ = unsent.waitForExistence(timeout: Wait.appear)
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: unsent)], timeout: Wait.firstLoad)
        XCTAssertEqual(stub.lastUpload()?.fields["send_key"]?.first, sendKey)
    }

    func testTheShareSheetShowsItsChipsAndSend() throws {
        try stub.play("paired")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token", "-yakTestShare", "ready"]
        app.launchRetryingOnce()
        XCTAssertTrue(app.buttons["share.repository"].waitForExistence(timeout: Wait.firstLoad))
        XCTAssertTrue(app.buttons["share.mode"].exists)
        XCTAssertTrue(app.buttons["share.model"].exists)
        let send = app.buttons["share.send"]
        XCTAssertTrue(send.waitForExistence(timeout: Wait.appear))
        app.textFields["share.text"].tap()
        app.textFields["share.text"].typeText("Look at this")
        XCTAssertTrue(send.isEnabled)
    }
}
