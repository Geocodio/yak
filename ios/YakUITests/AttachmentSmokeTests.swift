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
        try launch(scenario: "chat-attachments", arguments: keep + ["-yakTestUploadFailure", "YES"])
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

    func testFilesOnAMessageOpen() throws {
        try stub.play("chat-attachments")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launchRetryingOnce()
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: Wait.appear) { threads.tap() }
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:d0e515ef"]
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        let tile = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier ENDSWITH %@", ".attachment.41")
        ).firstMatch
        XCTAssertTrue(tile.waitForExistence(timeout: Wait.firstLoad))
        // A tile that is still loading is disabled; a failed one says "Retry" as its value.
        let loaded = NSPredicate(format: "isEnabled == true")
        wait(for: [XCTNSPredicateExpectation(predicate: loaded, object: tile)], timeout: Wait.firstLoad)
        XCTAssertTrue((tile.value as? String ?? "").isEmpty, "the tile did not load its image: \(tile.value ?? "")")
        let file = app.descendants(matching: .any).matching(
            NSPredicate(format: "identifier ENDSWITH %@", ".attachment.42")
        ).firstMatch
        XCTAssertTrue(file.waitForExistence(timeout: Wait.appear))
        file.tap()
        let preview = app.descendants(matching: .any)["QLPreviewControllerView"]
        let opened = preview.waitForExistence(timeout: Wait.firstLoad)
        if !opened {
            XCTAssertFalse(
                (file.value as? String ?? "").contains("could not be loaded"), "the file did not download")
        }
        XCTAssertTrue(opened, "Quick Look did not appear")
        // Quick Look's close control is a button named "close" with this identifier on the current SDK.
        let close = app.buttons["QLOverlayDoneButtonAccessibilityIdentifier"]
        XCTAssertTrue(close.waitForExistence(timeout: Wait.appear), "Quick Look has no close control")
        // A tap that lands while Quick Look is still presenting is swallowed on a hosted simulator, so the tap
        // repeats until the close control is gone, and each step fails by its own name.
        let hittable = NSPredicate(format: "isHittable == true")
        wait(for: [XCTNSPredicateExpectation(predicate: hittable, object: close)], timeout: Wait.appear)
        let closeGone = NSPredicate(format: "exists == false")
        var closed = false
        for _ in 0..<4 where !closed {
            if close.exists, close.isHittable { close.tap() }
            closed =
                XCTWaiter().wait(
                    for: [XCTNSPredicateExpectation(predicate: closeGone, object: close)], timeout: 15) == .completed
        }
        XCTAssertTrue(closed, "tapping Quick Look's close control did not close it")
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: preview)], timeout: Wait.appear)
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
