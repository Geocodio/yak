import Foundation
import Testing

@testable import YakKit

private func card(
    _ scenario: String, canDrive: Bool? = true, blockedReason: String? = nil, isRestoring: Bool = false,
    sandboxState: String? = nil, hasKnownPreviewURL: Bool = false, restoreTookTooLong: Bool = false,
    pendingIntent: MessageIntent? = nil, terminalAvailable: Bool = false
) throws -> PreviewCard {
    PreviewCard.make(
        status: try decoded(PreviewStatus.self, "scenario-preview-\(scenario)"), canDrive: canDrive,
        blockedReason: blockedReason, isRestoring: isRestoring, sandboxState: sandboxState,
        hasKnownPreviewURL: hasKnownPreviewURL, restoreTookTooLong: restoreTookTooLong,
        pendingIntent: pendingIntent, terminalAvailable: terminalAvailable)
}

@Test func everyStateHasItsCard() throws {
    #expect(try card("ready") == PreviewCard(kind: .page, actions: []))
    #expect(try card("waking").kind == .waking(steps: [.active, .pending, .pending]))
    #expect(try card("starting").kind == .waking(steps: [.done, .active, .pending]))
    #expect(try card("waiting-for-slot") == PreviewCard(kind: .waitingForSlot, actions: []))
    #expect(
        try card("failing")
            == PreviewCard(kind: .failing, actions: [.ask(.fixPreview, isPending: false), .tryAgain]))
    #expect(
        try card("fixing") == PreviewCard(kind: .setupRunning(isAdding: false), actions: [.showConversation]))
    #expect(
        try card("no-manifest")
            == PreviewCard(kind: .noManifest, actions: [.ask(.addPreviewManifest, isPending: false)]))
    #expect(
        try card("invalid-manifest")
            == PreviewCard(
                kind: .invalidManifest, actions: [.ask(.fixPreview, isPending: false), .tryAgain]))
    #expect(
        try card("destroyed", sandboxState: "destroyed")
            == PreviewCard(
                kind: .destroyed(isReadOnly: false, tookTooLong: false, neverHadSandbox: false), actions: [.restore]))
    #expect(try card("closed") == PreviewCard(kind: .closed, actions: []))
    #expect(try card("local-machine") == PreviewCard(kind: .localMachine, actions: []))
}

@Test func recipientsSeeNoAskOrRestore() throws {
    #expect(try card("failing", canDrive: false).actions == [.tryAgain])
    #expect(try card("no-manifest", canDrive: false).actions == [])
    #expect(try card("invalid-manifest", canDrive: false).actions == [.tryAgain])
    #expect(
        try card("destroyed", canDrive: false)
            == PreviewCard(
                kind: .destroyed(isReadOnly: true, tookTooLong: false, neverHadSandbox: false), actions: []))
    #expect(
        try card("destroyed", canDrive: false, blockedReason: "restore_failed")
            == PreviewCard(kind: .restoreFailed(isReadOnly: true), actions: []))
    #expect(try card("failing", canDrive: nil).actions == [.tryAgain])
}

@Test func aThreadThatNeverHadASandbox() throws {
    #expect(
        try card("destroyed", sandboxState: "none")
            == PreviewCard(
                kind: .destroyed(isReadOnly: false, tookTooLong: false, neverHadSandbox: true), actions: [.restore]))
}

@Test func restoringAndItsEnds() throws {
    #expect(try card("destroyed", isRestoring: true) == PreviewCard(kind: .restoring, actions: []))
    #expect(
        try card("destroyed", blockedReason: "restore_failed")
            == PreviewCard(kind: .restoreFailed(isReadOnly: false), actions: [.retryRestore]))
    #expect(
        try card("destroyed", restoreTookTooLong: true)
            == PreviewCard(
                kind: .destroyed(isReadOnly: false, tookTooLong: true, neverHadSandbox: false), actions: [.restore]))
    #expect(try card("waking", isRestoring: true).kind == .waking(steps: [.active, .pending, .pending]))
}

@Test func aRestoreThatFailedLateStillShowsRetry() throws {
    for scenario in ["starting", "failing", "ready"] {
        #expect(
            try card(scenario, blockedReason: "restore_failed")
                == PreviewCard(kind: .restoreFailed(isReadOnly: false), actions: [.retryRestore]))
    }
    #expect(try card("closed", blockedReason: "restore_failed").kind == .closed)
    #expect(try card("local-machine", blockedReason: "restore_failed").kind == .localMachine)
}

@Test func aPendingAskShowsProgress() throws {
    #expect(try card("failing", pendingIntent: .fixPreview).actions.first == .ask(.fixPreview, isPending: true))
    #expect(
        try card("failing", pendingIntent: .addPreviewManifest).actions.first == .ask(.fixPreview, isPending: false))
}

@Test func theTerminalButtonNeedsTheTerminalPane() throws {
    #expect(
        try card("failing", terminalAvailable: true).actions
            == [.ask(.fixPreview, isPending: false), .openTerminal, .tryAgain])
}

@Test func aSetupThatEndedOffersAskAgain() throws {
    let status = try JSONDecoder().decode(
        PreviewStatus.self,
        from: Scenarios.data(
            "scenario-preview-no-manifest", setting: ["setup", "endedWithoutManifest"], toJSON: "true"))
    #expect(
        PreviewCard.make(status: status, canDrive: true, blockedReason: nil, isRestoring: false)
            == PreviewCard(kind: .setupEnded, actions: [.ask(.addPreviewManifest, isPending: false)]))
}

@Test func anUnknownStateGetsTheFallback() throws {
    let status = try JSONDecoder().decode(
        PreviewStatus.self,
        from: Scenarios.data("scenario-preview-ready", setting: ["state"], toJSON: "\"hibernating\""))
    func make(known: Bool) -> PreviewCard {
        PreviewCard.make(
            status: status, canDrive: true, blockedReason: nil, isRestoring: false, hasKnownPreviewURL: known)
    }
    #expect(make(known: true) == PreviewCard(kind: .unknown(state: "hibernating"), actions: [.openInBrowser]))
    #expect(make(known: false) == PreviewCard(kind: .unknown(state: "hibernating"), actions: []))
}
