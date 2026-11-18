import XCTest

/// The longest waits of the UI tests, in one place. A wait for something to appear or to become true costs time only
/// when something is wrong, and a hosted runner is slow, so these are generous.
enum Wait {
    /// Something appears or becomes true.
    static let appear: TimeInterval = 60
    /// The first page or thread load after a launch.
    static let firstLoad: TimeInterval = 120
}

extension XCUIApplication {
    /// Launches the app; when it is not running in the foreground within a minute, terminates it and launches once more.
    /// A hosted simulator sometimes fails the first launch ("does not have a process ID").
    func launchRetryingOnce() {
        launch()
        if !wait(for: .runningForeground, timeout: Wait.appear) {
            terminate()
            launch()
        }
    }
}
