import XCTest

/// The walkthrough player against the stub's sample video.
@MainActor final class LeftoversSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
    }

    private func element(_ identifier: String) -> XCUIElement { app.descendants(matching: .any)[identifier] }

    func testWalkthroughPlaysAndCloses() throws {
        try stub.play("task-walkthrough")
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.open(stub.taskLink)
        let row = element("task.walkthrough")
        XCTAssertTrue(row.waitForExistence(timeout: Wait.firstLoad))
        row.tap()
        XCTAssertTrue(element("walkthrough.player").waitForExistence(timeout: Wait.appear))
        let failure = NSPredicate(format: "identifier BEGINSWITH 'walkthrough.failed.'")
        XCTAssertFalse(app.descendants(matching: .any).matching(failure).firstMatch.waitForExistence(timeout: 10))
        element("walkthrough.close").tap()
        XCTAssertTrue(element("task.screen").waitForExistence(timeout: 10))
        let log = try stub.walkthroughVideoLog()
        XCTAssertTrue(log.contains { $0.path == "/__stub/walkthrough-video/sample.mp4" && $0.hasRange })
        XCTAssertFalse(log.contains { $0.hasAuthorization })
    }
}
