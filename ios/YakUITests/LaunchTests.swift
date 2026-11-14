import XCTest

@MainActor final class LaunchTests: XCTestCase {
    func testLaunchShowsConnectScreen() {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.staticTexts["Connect to your Yak"].waitForExistence(timeout: 5))
    }
}
