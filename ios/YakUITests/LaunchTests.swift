import XCTest

@MainActor final class LaunchTests: XCTestCase {
    func testLaunchShowsConnectScreen() throws {
        let stub = try StubControl()
        try stub.play("not-yak")
        let app = XCUIApplication()
        app.launchArguments = ["-yakTestInstance", stub.url]
        app.launchRetryingOnce()
        XCTAssertTrue(app.staticTexts["Connect to your Yak"].waitForExistence(timeout: Wait.firstLoad))
    }
}
