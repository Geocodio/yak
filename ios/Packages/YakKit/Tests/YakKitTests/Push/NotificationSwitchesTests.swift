import Testing

@testable import YakKit

struct NotificationSwitchesTests {
    @Test func aSwitchSavesOnceAndShowsItsNewValue() throws {
        var switches = NotificationSwitches(
            saved: try decoded(NotificationPreferences.self, "scenario-push-preferences"))
        let sent = switches.toggle(.turnFinished, to: false)
        let request = try #require(sent)
        #expect(request.event == .turnFinished && request.isOn == false)
        #expect(!switches.isOn(.turnFinished) && switches.isSaving(.turnFinished))
        #expect(switches.toggle(.turnFinished, to: true) == nil)
        #expect(switches.toggle(.stackUsage, to: true) != nil)
        switches.saved(
            try decoded(NotificationPreferences.self, "scenario-push-preferences-updated"), for: .turnFinished)
        #expect(!switches.isOn(.turnFinished) && !switches.isSaving(.turnFinished))
        #expect(switches.isOn(.stackUsage) && switches.isSaving(.stackUsage))
    }

    @Test func aFailedSaveReturnsTheSwitch() throws {
        var switches = NotificationSwitches(
            saved: try decoded(NotificationPreferences.self, "scenario-push-preferences"))
        #expect(switches.toggle(.needsYou, to: false) != nil)
        switches.saveFailed(.needsYou, problem: .offline)
        #expect(switches.isOn(.needsYou) && !switches.isSaving(.needsYou))
        #expect(switches.problem == .init(event: .needsYou, problem: .offline))
    }

    @Test func aSuccessClearsOnlyItsOwnProblem() throws {
        let preferences = try decoded(NotificationPreferences.self, "scenario-push-preferences")
        var switches = NotificationSwitches(saved: preferences)
        #expect(switches.toggle(.needsYou, to: false) != nil)
        switches.saveFailed(.needsYou, problem: .offline)
        #expect(switches.toggle(.turnFinished, to: false) != nil)
        switches.saved(preferences, for: .turnFinished)
        #expect(switches.problem?.event == .needsYou)
        #expect(switches.toggle(.needsYou, to: false) != nil)
        #expect(switches.problem == nil)
    }

    @Test func aSavedCopyShowsWhileLoading() throws {
        let switches = NotificationSwitches(
            saved: try decoded(NotificationPreferences.self, "scenario-push-preferences"))
        #expect(switches.load == .loading)
        #expect(!switches.isOn(.stackUsage) && switches.isOn(.needsYou))
        let empty = NotificationSwitches(saved: nil)
        #expect(empty.load == .loading)
        #expect(PushEvent.switches.allSatisfy { empty.isOn($0) })
    }

    @Test func loadingUpdatesTheValuesAndFailureKeepsThem() throws {
        var switches = NotificationSwitches(saved: nil)
        switches.loaded(try decoded(NotificationPreferences.self, "scenario-push-preferences"))
        #expect(switches.load == .loaded && !switches.isOn(.stackUsage))
        switches.loadFailed(.offline)
        #expect(switches.load == .failed(.offline) && !switches.isOn(.stackUsage))
    }

    @Test func unchangedValuesSendNothing() throws {
        var switches = NotificationSwitches(
            saved: try decoded(NotificationPreferences.self, "scenario-push-preferences"))
        #expect(switches.toggle(.needsYou, to: true) == nil)
        #expect(!switches.isSaving(.needsYou))
    }
}
