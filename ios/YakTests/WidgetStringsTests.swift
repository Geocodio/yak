import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct WidgetStringsTests {
    private func text(_ resource: LocalizedStringResource) -> String { String(localized: resource) }

    @Test func everyStateHasItsStrings() {
        let states: [WidgetState] = [
            .noInstance, .instanceMissing, .needsPairing(host: "yak.example.com"), .notLoaded(host: "yak.example.com"),
        ]
        for state in states {
            for resource in [WidgetStrings.stateText(state), WidgetStrings.stateShort(state)] {
                let value = text(resource)
                #expect(!value.isEmpty && !value.hasPrefix("widget."), "\(state) shows its key")
            }
        }
        #expect(
            text(WidgetStrings.stateText(.needsPairing(host: "yak.example.com")))
                == "Pair yak.example.com again in Yak.")
        #expect(text(WidgetStrings.stateCircular(.notLoaded(host: "")) ?? WidgetStrings.brand) == "No data")
    }

    @Test func theLockScreenLabelLeavesTheTitleOut() {
        let label = text(WidgetStrings.a11yNeedsYouLock(count: "2", instance: "Example Yak"))
        #expect(label == "2 need you on Example Yak.")
        #expect(!label.contains("First"))
    }

    @Test func everyLabelAndTitleResolves() {
        let resources: [LocalizedStringResource] = [
            WidgetStrings.brand, WidgetStrings.privateThread, WidgetStrings.unknownThread, WidgetStrings.working,
            WidgetStrings.needsYouHeader, WidgetStrings.runningHeader, WidgetStrings.needsYouEmpty,
            WidgetStrings.runningEmpty, WidgetStrings.lockOne, WidgetStrings.lockNone, WidgetStrings.lockMany("5"),
            WidgetStrings.needsYouHeader(on: "Example Yak"), WidgetStrings.runningHeader(on: "Example Yak"),
            WidgetStrings.title(.task("Review the import job")), WidgetStrings.updated("2h"),
            WidgetStrings.opensThread, WidgetStrings.opensYak,
            WidgetStrings.a11yNeedsYou(count: "2", instance: "Example Yak", first: "A private thread"),
            WidgetStrings.a11yNeedsYouNone(instance: "Example Yak"),
            WidgetStrings.a11yRunning(count: "3", instance: "Example Yak"),
            WidgetStrings.a11yRunningNone(instance: "Example Yak"),
        ]
        for resource in resources {
            let value = text(resource)
            #expect(!value.isEmpty && !value.hasPrefix("widget."), "\(value)")
        }
        #expect(text(WidgetStrings.title(.task("Review the import job"))) == "Task: Review the import job")
        #expect(text(WidgetStrings.title(.unknown)) == "Your last thread")
        #expect(text(WidgetStrings.lockMany("99+")) == "Yak: 99+ need you")
    }

    @Test func everyReasonHasItsWords() {
        let expected: [(NeedsYouReason?, String)] = [
            (.question, "Asked you a question"), (.blocked, "Blocked, needs you"),
            (.unseen, "Finished. Open Yak to see it."), (.coOwner, "Wants you as co-owner"),
            (NeedsYouReason(rawValue: "something_new"), "Waiting for you"), (nil, "Waiting for you"),
        ]
        for (reason, words) in expected { #expect(text(WidgetStrings.reason(reason)) == words) }
    }

    @Test func ageIsShort() {
        let now = Date()
        #expect(text(WidgetStrings.age(from: now.addingTimeInterval(-10), to: now)) == "now")
        #expect(text(WidgetStrings.age(from: now.addingTimeInterval(-125), to: now)) == "2m")
        #expect(text(WidgetStrings.age(from: now.addingTimeInterval(-7300), to: now)) == "2h")
        #expect(text(WidgetStrings.age(from: now.addingTimeInterval(-200_000), to: now)) == "2d")
    }

    @Test func theCircularLabelFits() {
        #expect(WidgetText.countLabel(1_000_000).count <= 3)
        #expect(text(WidgetStrings.brand).count <= 3)
    }

    @Test func theDisplayFollowsTheContent() {
        let instance = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
            lastUsedAt: Date())
        let content = NeedsYouWidgetContent.make(
            snapshot: nil, state: .noInstance, instance: instance, size: .small, now: Date())
        let display = WidgetDisplay(content)
        #expect(display.kind == .needsYou && display.state == .noInstance && display.rows.isEmpty)
    }
}
