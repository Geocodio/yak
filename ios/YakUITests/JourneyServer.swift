import XCTest

/// The world `yak:e2e-seed` prints, as the `e2e` lane passes it in `TEST_RUNNER_YAK_E2E_SEED` (base64 JSON).
struct JourneySeed: Decodable, Sendable {
    struct Phone: Decodable, Sendable {
        let name: String
        let token: String
    }
    struct SeededThread: Decodable, Sendable {
        let id: String
        let title: String
    }
    struct Phones: Decodable, Sendable {
        let main: Phone
        let second: Phone
    }
    struct Threads: Decodable, Sendable {
        let finished: SeededThread
        let question: SeededThread
        let held: SeededThread
        let changes: SeededThread
    }
    let url: String
    let person: String
    let phones: Phones
    let threads: Threads
}

/// The real Yak the journeys drive, started by `bundle exec fastlane e2e`.
struct JourneyServer {
    let seed: JourneySeed

    var url: String { seed.url }

    /// The address as a person types it: the URL without its scheme.
    var address: String { url.replacingOccurrences(of: "http://", with: "") }

    init() throws {
        let message = "Run the journeys with bundle exec fastlane e2e."
        let encoded = try XCTUnwrap(ProcessInfo.processInfo.environment["YAK_E2E_SEED"], message)
        let data = try XCTUnwrap(Data(base64Encoded: encoded), message)
        seed = try XCTUnwrap(try? JSONDecoder().decode(JourneySeed.self, from: data), message)
    }

    /// Launch arguments for a start paired with one of the seed's phones, or an unpaired start.
    func launchArguments(phone: JourneySeed.Phone?) -> [String] {
        var arguments = ["-yakTestInstance", url]
        if let phone { arguments += ["-yakTestToken", phone.token] }
        return arguments
    }
}

/// Bounded waits and the audit for the journeys. Every wait names what it waited for.
@MainActor enum JourneyWait {
    /// Waits for the element to exist (or to stop existing) within the bound; on timeout attaches a screenshot and
    /// fails with a message naming what it waited for.
    static func until(
        _ element: XCUIElement, exists: Bool = true, within seconds: TimeInterval, _ what: String,
        in test: XCTestCase
    ) {
        let deadline = Date().addingTimeInterval(seconds)
        while element.exists != exists, Date() < deadline {
            _ = element.waitForExistence(timeout: exists ? 1 : 0.2)
            if !exists { RunLoop.current.run(until: Date().addingTimeInterval(0.3)) }
        }
        guard element.exists != exists else { return }
        attachScreenshot(named: "timeout", in: test)
        XCTFail("Waited \(Int(seconds)) s for \(what) \(exists ? "to show" : "to go"), \(element)")
    }

    /// Waits until the element's label equals the text.
    static func untilLabel(
        _ element: XCUIElement, is text: String, within seconds: TimeInterval, _ what: String, in test: XCTestCase
    ) {
        let expectation = XCTNSPredicateExpectation(
            predicate: NSPredicate(format: "label == %@", text), object: element)
        guard XCTWaiter().wait(for: [expectation], timeout: seconds) == .completed else {
            attachScreenshot(named: "timeout", in: test)
            XCTFail("Waited \(Int(seconds)) s for \(what) to read \"\(text)\", it reads \"\(element.label)\"")
            return
        }
    }

    static func attachScreenshot(named name: String, in test: XCTestCase) {
        let screenshot = XCTAttachment(screenshot: XCUIScreen.main.screenshot())
        screenshot.lifetime = .keepAlways
        screenshot.name = name
        test.add(screenshot)
    }

    /// The accessibility audit with the shared rules and the smoke tests' one exception (the one-line address field).
    static func audit(_ app: XCUIApplication, in test: XCTestCase) throws {
        let bars = AuditRules.Bars(app)
        try app.performAccessibilityAudit { issue in
            let systemBars = app.navigationBars.allElementsBoundByIndex + app.toolbars.allElementsBoundByIndex
            if let element = issue.element, systemBars.contains(where: { $0.frame.contains(element.frame) }) {
                // The system caps the size of text in its bars, as the thread smoke tests allow.
                if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
            }
            if AuditRules.isContrastOfAnElementOffScreen(issue, bars: bars) { return true }
            if AuditRules.isContrastOfTextBehindABar(issue, bars: bars) { return true }
            if issue.auditType == .textClipped, issue.element?.elementType == .textField { return true }
            attachScreenshot(named: "audit-failure", in: test)
            XCTFail(
                "audit: \(issue.compactDescription) | \(issue.detailedDescription) | \(issue.element?.debugDescription ?? "no element")"
            )
            return true
        }
    }
}
