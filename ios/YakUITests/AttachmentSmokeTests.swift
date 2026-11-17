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
        let unsent = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH %@", "unsent.")
        ).firstMatch
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: unsent)], timeout: Wait.firstLoad)
    }

    func testAFailedUploadGoesAgainOnTheNextLaunch() throws {
        let runID = UUID().uuidString
        let keep = ["-yakTestKeepFiles", runID]
        try launch(scenario: "upload-fails", arguments: keep)
        openThreadAndSend("This one fails first")
        let failed = app.staticTexts["Not sent. Tap Retry, or it tries again later."]
        XCTAssertTrue(failed.waitForExistence(timeout: Wait.firstLoad))
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
        let unsent = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier BEGINSWITH %@", "unsent.")
        ).firstMatch
        _ = unsent.waitForExistence(timeout: Wait.appear)
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: unsent)], timeout: Wait.firstLoad)
        XCTAssertEqual(stub.lastUpload()?.fields["send_key"]?.first, sendKey)
    }
}
