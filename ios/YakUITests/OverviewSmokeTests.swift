import XCTest

/// Smoke tests of the Overview against the stub server's `overview-*` scenarios.
@MainActor final class OverviewSmokeTests: XCTestCase {
    private var stub: StubControl!
    private let app = XCUIApplication()

    override func setUp() async throws {
        continueAfterFailure = false
        stub = try StubControl()
        XCUIDevice.shared.orientation = .portrait
    }

    private func launch(scenario: String) throws {
        try stub.play(scenario)
        app.launchArguments = ["-yakTestInstance", stub.url, "-yakTestToken", "stub-token"]
        app.launch()
    }

    private func openThread(_ id: String) {
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: 60) { threads.tap() }
        }
        let row = app.descendants(matching: .any)["drawer.row.thread:\(id)"]
        XCTAssertTrue(row.waitForExistence(timeout: 60))
        row.tap()
    }

    func testOverviewOpensFromTheTitleAndShowsTheGroups() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 60))
        title.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview"].waitForExistence(timeout: 60))
        let kinds = ["subThreads", "status", "links", "access", "people"]
        let groups = kinds.map { app.descendants(matching: .any)["overview.group.\($0)"] }
        for group in groups { XCTAssertTrue(group.waitForExistence(timeout: 60), group.identifier) }
        let tops = groups.map(\.frame.minY)
        XCTAssertEqual(tops, tops.sorted())
        XCTAssertTrue(app.staticTexts["Sandbox running"].exists)
        XCTAssertTrue(app.staticTexts["2 of 4 slots in use"].exists)
        try audit()
    }

    func testStopAllAsksFirstAndHidesItself() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 60))
        title.tap()
        let menu = app.buttons["overview.menu"]
        XCTAssertTrue(menu.waitForExistence(timeout: 60))
        menu.tap()
        let stop = app.buttons["Stop all sub-threads"]
        XCTAssertTrue(stop.waitForExistence(timeout: 60))
        stop.tap()
        XCTAssertTrue(app.staticTexts["Stop all sub-threads?"].waitForExistence(timeout: 60))
        app.buttons["Stop all"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: menu)], timeout: 10)
        XCTAssertTrue(
            app.staticTexts["This stack was stopped, so it cannot start sub-threads."].waitForExistence(timeout: 60))
        XCTAssertTrue(try stub.lastOverviewRequest().hasPrefix("POST threads/s7ackr00/stack/stop"))
        try audit()
    }

    /// Every sub-thread state reads in the web's words, on a screen where all the rows are fully visible.
    func testSubThreadStatesReadInTheWebsWords() throws {
        try launch(scenario: "overview-states")
        try openOverview()
        let heading = app.descendants(matching: .any).matching(
            NSPredicate(format: "label == %@", "Sub-threads, 4 of 12"))
        XCTAssertTrue(heading.firstMatch.waitForExistence(timeout: 60))
        XCTAssertTrue(app.staticTexts["overview.definition"].exists)
        XCTAssertFalse(app.buttons["overview.messages"].exists)
        let expected = [
            "b4ckf111": ["backfill-job", "Implement \u{00B7} example/web \u{00B7} Working", "Writing the migration"],
            "q7adapt0": [
                "query-adapter", "Implement \u{00B7} example/web \u{00B7} Waiting",
                "Waiting for a free spot. 2 sub-threads",
            ],
            "s3ttings": ["settings-page", "Implement \u{00B7} example/web \u{00B7} Starting"],
            "r3search": ["index-settings", "Research \u{00B7} Done", "The analyzer needs the ascii folding filter."],
        ]
        for (id, parts) in expected {
            let row = app.buttons["overview.child.\(id)"]
            XCTAssertTrue(row.waitForExistence(timeout: 60), id)
            for part in parts { XCTAssertTrue(row.label.contains(part), "\(id): \(row.label)") }
        }
        XCTAssertTrue(app.buttons["overview.child.q7adapt0.startNow"].label == "Start now and allow 3 at a time")
        XCTAssertTrue(app.staticTexts["2 can run at the same time"].exists)
        try audit()
    }

    func testTheOtherSubThreadStatesReadInTheWebsWords() throws {
        try launch(scenario: "overview-states-more")
        try openOverview()
        let expected = [
            "n33dsyou": "Implement \u{00B7} example/web \u{00B7} Needs you",
            "f41led00": "Implement \u{00B7} example/web \u{00B7} Failed",
            "st0pped0": "Implement \u{00B7} example/web \u{00B7} Stopped",
        ]
        for (id, line) in expected {
            let row = app.buttons["overview.child.\(id)"]
            XCTAssertTrue(row.waitForExistence(timeout: 60), id)
            XCTAssertTrue(row.label.contains(line), "\(id): \(row.label)")
        }
        XCTAssertFalse(app.buttons["overview.child.n33dsyou.startNow"].exists)
        try audit()
    }

    func testAnUnknownStateAndATakenOverSubThreadReadNeutrally() throws {
        try launch(scenario: "overview-states-other")
        try openOverview()
        let unknown = app.buttons["overview.child.unkn0wn0"]
        XCTAssertTrue(unknown.waitForExistence(timeout: 60))
        XCTAssertTrue(unknown.label.contains("Research \u{00B7} Other"), unknown.label)
        let taken = app.buttons["overview.child.t4k3n000"]
        XCTAssertTrue(taken.label.contains("Taken over \u{00B7} messages paused"), taken.label)
        try audit()
    }

    func testStartNowRaisesTheLimitOnceAndChangeShowsTheStepper() throws {
        try launch(scenario: "overview-states")
        try openOverview()
        let startNow = app.buttons["overview.child.q7adapt0.startNow"]
        XCTAssertTrue(startNow.waitForExistence(timeout: 60))
        startNow.tap()
        let line = app.staticTexts["3 can run at the same time"]
        XCTAssertTrue(line.waitForExistence(timeout: 60))
        let request = try stub.lastOverviewRequest()
        XCTAssertTrue(request.hasPrefix("PATCH threads/s7ackr00/stack/limit"), request)
        XCTAssertTrue(request.contains("\"limit\":3"), request)
        XCTAssertTrue(app.buttons["overview.child.q7adapt0.startNow"].label == "Start now and allow 4 at a time")
        app.buttons["overview.limit.change"].tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.awake"].waitForExistence(timeout: 60))
        XCTAssertTrue(
            app.staticTexts.matching(
                NSPredicate(format: "label BEGINSWITH %@", "Sub-threads with their own sandbox take turns.")
            ).firstMatch.exists)
        XCTAssertFalse(line.exists)
    }

    private func openOverview() throws {
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 60))
        title.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.group.subThreads"].waitForExistence(timeout: 60))
    }

    func testAddingASubThreadSendsOnceAndShowsIt() throws {
        try launch(scenario: "overview-stack")
        openThread("s7ackr00")
        let plus = app.buttons["composer.plus"]
        XCTAssertTrue(plus.waitForExistence(timeout: 60))
        plus.tap()
        let addItem = app.buttons["composer.plusMenu.addSubThread"]
        XCTAssertTrue(addItem.waitForExistence(timeout: 60))
        addItem.tap()
        let name = app.textFields["addSubThread.name"]
        XCTAssertTrue(name.waitForExistence(timeout: 60))
        name.tap()
        name.typeText("check-the-cache")
        let brief = app.textFields["addSubThread.brief"]
        brief.tap()
        brief.typeText("Look at the cache keys.")
        app.segmentedControls["addSubThread.kind"].buttons["Research"].tap()
        // The keyboard covers the footer rows, so it goes away before the audit looks at them.
        app.otherElements["addSubThread.sheet"].scrollViews.firstMatch.swipeDown()
        // The audit judges what is on screen, so the sheet is pulled to its large size where its footer is not cut.
        let grabber = app.staticTexts["Add a sub-thread"].coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0))
        let top = app.windows.firstMatch.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.05))
        grabber.press(forDuration: 0.1, thenDragTo: top)
        let footer = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Runs on")).firstMatch
        XCTAssertTrue(footer.waitForExistence(timeout: 60))
        try audit()
        app.buttons["addSubThread.add"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: name)], timeout: 15)
        let request = try stub.lastOverviewRequest()
        XCTAssertTrue(request.contains("\"kind\":\"research\""), request)
        XCTAssertTrue(request.contains("send_key"), request)
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 60))
        title.tap()
        XCTAssertTrue(app.descendants(matching: .any)["overview.child.n3wch11d"].waitForExistence(timeout: 60))
        try audit()
    }

    func testLongPressOnAFailedSubThreadOffersItsActions() throws {
        try launch(scenario: "overview-stack")
        if !app.otherElements["drawer"].exists {
            let threads = app.buttons["threads.button"]
            if threads.waitForExistence(timeout: 60) { threads.tap() }
        }
        let failed = app.descendants(matching: .any)["drawer.row.thread:q7adapt0"]
        if !failed.waitForExistence(timeout: 60) {
            let toggle = app.buttons["drawer.toggle.thread:s7ackr00"]
            XCTAssertTrue(toggle.waitForExistence(timeout: 60))
            toggle.tap()
        }
        XCTAssertTrue(failed.waitForExistence(timeout: 60))
        // The menu reads the stack the drawer fetched when the parent opened, so the press repeats until it has the
        // actions.
        let drop = app.buttons["Drop it"]
        for _ in 0..<8 where !drop.exists {
            failed.press(forDuration: 1.0)
            if !drop.waitForExistence(timeout: 60) { app.tap() }
        }
        XCTAssertTrue(drop.exists)
        let takeOver = app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS %@", "Take over"))
        XCTAssertTrue(takeOver.firstMatch.waitForExistence(timeout: 60), app.debugDescription)
        XCTAssertTrue(app.buttons["Split it up"].exists)
        // A tap inside the drawer closes the menu and leaves the drawer open.
        app.otherElements["drawer"].coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.95)).tap()
        let working = app.descendants(matching: .any)["drawer.row.thread:b4ckf111"]
        XCTAssertTrue(working.waitForExistence(timeout: 60))
        working.press(forDuration: 1.0)
        XCTAssertTrue(app.buttons["Open"].waitForExistence(timeout: 60))
        XCTAssertFalse(app.buttons["Drop it"].exists)
        app.tap()
        try audit()
    }

    func testSharingWithEveryoneUpdatesThePeopleRow() throws {
        try launch(scenario: "overview-private")
        openThread("s7ackr00")
        let title = app.descendants(matching: .any)["thread.title"]
        XCTAssertTrue(title.waitForExistence(timeout: 60))
        title.tap()
        XCTAssertTrue(app.staticTexts["Shared with 1 person"].waitForExistence(timeout: 60))
        XCTAssertTrue(app.buttons["overview.shareButton"].exists)
        let change = app.buttons["overview.share"]
        for _ in 0..<6 where !change.isHittable { app.descendants(matching: .any)["overview"].swipeUp() }
        change.tap()
        let everyone = app.buttons["share.mode.everyone"]
        XCTAssertTrue(everyone.waitForExistence(timeout: 60))
        everyone.tap()
        app.buttons["share.save"].tap()
        let gone = NSPredicate(format: "exists == false")
        wait(for: [XCTNSPredicateExpectation(predicate: gone, object: everyone)], timeout: 15)
        XCTAssertTrue(app.staticTexts["Shared with the team"].waitForExistence(timeout: 60))
        // A heading just under the floating top buttons reads as low contrast, so the page goes back to its top.
        app.descendants(matching: .any)["overview"].swipeDown(velocity: .fast)
        try audit()
    }

    private func audit() throws {
        try auditTwice(app) { [app] in
            let auditBars = AuditRules.Bars(app)
            return { issue in
                // A bottom toolbar can have a frame that covers the content, so a toolbar only counts when it leaves the
                // centre of the window free.
                let centre = CGPoint(x: auditBars.window.midX, y: auditBars.window.midY)
                let bars =
                    app.navigationBars.allElementsBoundByIndex
                    + app.toolbars.allElementsBoundByIndex.filter { !$0.frame.contains(centre) }
                if let element = issue.element, bars.contains(where: { $0.frame.contains(element.frame) }) {
                    // The system caps the size of text in its bars.
                    if [.dynamicType, .textClipped].contains(issue.auditType) { return true }
                }
                // An element entirely outside the screen shows no pixels to measure.
                if AuditRules.isContrastOfAnElementOffScreen(issue, bars: auditBars) { return true }
                // Text behind a bar is covered by the bar.
                if AuditRules.isContrastOfTextBehindABar(issue, bars: auditBars) { return true }
                return false
            }
        }
    }
}
