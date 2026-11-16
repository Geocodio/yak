import Foundation
import Testing

@testable import YakKit

private let title = "Retry failed rows when the import times out"
private let start = Date(timeIntervalSince1970: 1_794_830_400)  // 2026-11-16 12:00 UTC

private func content(
    _ state: String, step: String = "", isPrivate: Bool = false, startedAt: String = "2026-11-16T12:00:00+00:00"
)
    -> ThreadActivityContentState
{
    ThreadActivityContentState(
        state: ThreadActivityState(rawValue: state), step: step, turnStartedAt: startedAt, isPrivate: isPrivate)
}

private func look(
    _ content: ThreadActivityContentState, title: String = title, stale: Bool = false, limited: Bool = false
) -> LiveActivityLook {
    LiveActivityLook.make(content: content, title: title, isStale: stale, isLimitedWidth: limited)
}

@Test func workingShowsTheStepAndATimer() throws {
    let result = look(try decoded(ThreadActivityContentState.self, "scenario-push-activity-working"))
    #expect(result.glyph == .working && result.title == .text(title) && result.detail == .step("Running a command"))
    #expect(result.timerStart == start && result.compactTrailing == .timer(start) && !result.isEnded)
}

@Test func aWorkingActivityWithoutAStepSaysWorking() {
    #expect(look(content("working")).detail == .working)
}

@Test func privateNeverShowsTitleOrStep() throws {
    let result = look(try decoded(ThreadActivityContentState.self, "scenario-push-activity-private"))
    #expect(result.title == .privateThread && result.detail == .working)
}

@Test func aTeamThreadMadePrivateMidTurnHidesItsTitle() {
    let result = look(content("working", step: "Reading files", isPrivate: true), title: "Visible title")
    #expect(result.title == .privateThread && result.detail == .working)
}

@Test func aBlankTitleReadsAsPrivate() {
    #expect(look(content("working"), title: "  ").title == .privateThread)
}

@Test(arguments: [
    ("needs_you", LiveActivityGlyph.needsYou, LiveActivityDetail.needsYou, false),
    ("finished", .finished, .finished, true), ("failed", .failed, .failed, true),
    ("stopped", .stopped, .stopped, true), ("paused_by_admin", .working, .working, false),
])
func everyStateHasItsLook(state: String, glyph: LiveActivityGlyph, detail: LiveActivityDetail, isEnded: Bool) {
    let result = look(content(state))
    #expect(result.glyph == glyph && result.detail == detail && result.isEnded == isEnded)
    if state != "paused_by_admin" { #expect(result.timerStart == nil) }
}

@Test func aStaleActivitySaysSoAndStopsTheTimer() {
    let stale = look(content("working", step: "Running a command"), stale: true)
    #expect(stale.glyph == .stale && stale.detail == .noRecentUpdate && stale.timerStart == nil)
    #expect(stale.compactTrailing == .glyph)
    let finished = look(content("finished"), stale: true)
    #expect(finished.glyph == .finished && finished.detail == .finished)
}

@Test func theLimitedWidthPillNeverShowsATimer() {
    #expect(look(content("working"), limited: true).compactTrailing == .brand)
    #expect(look(content("needs_you"), limited: true).compactTrailing == .brand)
    #expect(look(content("needs_you")).compactTrailing == .glyph)
}

@Test func aBadStartTimeShowsNoTimer() {
    let result = look(content("working", startedAt: "yesterday"))
    #expect(result.timerStart == nil && result.compactTrailing == .glyph)
}

@Test func fractionalSecondsParse() {
    #expect(
        look(content("working", startedAt: "2026-11-16T12:00:00.250+00:00")).timerStart
            == start.addingTimeInterval(0.25))
}
