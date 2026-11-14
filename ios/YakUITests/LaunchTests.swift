import XCTest

final class LaunchTests: XCTestCase {
    func testLaunchShowsTitle() {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.staticTexts["Yak"].waitForExistence(timeout: 5))
    }
}
