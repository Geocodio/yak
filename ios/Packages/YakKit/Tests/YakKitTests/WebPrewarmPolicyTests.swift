import Testing

@testable import YakKit

@Suite struct WebPrewarmPolicyTests {
    private let ready = WebPrewarmPolicy(
        isActive: true, isLocked: false, isSessionActive: true, isOnline: true, isLowDataMode: false,
        serverOffersPage: true, hasPreparedPage: false, candidateThreadID: "d0e515ef")

    @Test func aPairedOnlineActiveAppPreparesTheCandidate() {
        #expect(ready.threadToPrepare == "d0e515ef")
    }

    @Test func nothingIsPreparedInTheBackgroundOrUnderTheLock() {
        var inBackground = ready
        inBackground.isActive = false
        #expect(inBackground.threadToPrepare == nil)
        var locked = ready
        locked.isLocked = true
        #expect(locked.threadToPrepare == nil)
    }

    @Test func nothingIsPreparedOfflineOrUnderLowDataMode() {
        var offline = ready
        offline.isOnline = false
        #expect(offline.threadToPrepare == nil)
        var lowData = ready
        lowData.isLowDataMode = true
        #expect(lowData.threadToPrepare == nil)
    }

    @Test func nothingIsPreparedWithoutASessionOrAServerThatOffersThePage() {
        var noSession = ready
        noSession.isSessionActive = false
        #expect(noSession.threadToPrepare == nil)
        var oldServer = ready
        oldServer.serverOffersPage = false
        #expect(oldServer.threadToPrepare == nil)
    }

    @Test func onePageIsPreparedAtMost() {
        var prepared = ready
        prepared.hasPreparedPage = true
        #expect(prepared.threadToPrepare == nil)
    }

    @Test func withoutACandidateThereIsNothingToPrepare() {
        var none = ready
        none.candidateThreadID = nil
        #expect(none.threadToPrepare == nil)
        none.candidateThreadID = ""
        #expect(none.threadToPrepare == nil)
    }
}
