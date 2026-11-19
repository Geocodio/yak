import Foundation
import Testing
import YakKit

@testable import Yak

private func instance(_ address: String) -> Instance {
    Instance(url: URL(string: address)!, name: "Example Yak", userName: "Test Person", lastUsedAt: Date())
}

@MainActor @Test func coldStartAppliesOnce() {
    let scene = SceneModel(state: SceneState())
    scene.applyColdStart(.thread("d0e515ef"))
    scene.applyColdStart(.newThread)
    #expect(scene.state.threadID == "d0e515ef")
    #expect(!scene.state.isNewThreadOpen)
    #expect(scene.hasAppliedColdStart)
}

@MainActor @Test func openingAThreadClosesTheDrawerOnCompactWidth() {
    let scene = SceneModel(state: SceneState(isDrawerOpen: true))
    scene.open(thread: "a1b2c3d4", isCompactWidth: false)
    #expect(scene.state.isDrawerOpen)
    scene.open(thread: "a1b2c3d4", isCompactWidth: true)
    #expect(!scene.state.isDrawerOpen)
    #expect(scene.state.threadID == "a1b2c3d4")
}

@MainActor @Test func aThreadLinkForAnotherInstanceSwitches() {
    let active = instance("https://yak.example.com")
    let other = instance("https://other.example.com")
    let scene = SceneModel(state: SceneState())
    let link = DeepLink.thread(instance: URL(string: "https://other.example.com/")!, id: "a1b2c3d4")
    let outcome = scene.handle(link, active: active, instances: [active, other], isCompactWidth: true)
    #expect(outcome == .switchInstance(other))
}

@MainActor @Test func anUnknownInstanceAsksToPair() {
    let active = instance("https://yak.example.com")
    let scene = SceneModel(state: SceneState())
    let link = DeepLink.thread(instance: URL(string: "https://other.example.com")!, id: "a1b2c3d4")
    let outcome = scene.handle(link, active: active, instances: [active], isCompactWidth: true)
    #expect(outcome == .unknownInstance(URL(string: "https://other.example.com")!))
}

@MainActor @Test func aTaskLinkOpensItsWebPage() {
    let active = instance("https://yak.example.com")
    let scene = SceneModel(state: SceneState())
    let link = DeepLink.task(instance: URL(string: "https://yak.example.com")!, id: "k3m9p2qa")
    let outcome = scene.handle(link, active: active, instances: [active], isCompactWidth: true)
    #expect(outcome == .openTaskPage(URL(string: "https://yak.example.com/tasks/k3m9p2qa")!))
}

@MainActor @Test func aRestoredStateRoundTrips() throws {
    let state = SceneState(
        threadID: "e5f6g7h8", focusedPane: .chat, isDrawerOpen: true, isNewThreadOpen: false, filter: .everyone,
        isOverviewPanelOpen: true)
    let data = try JSONEncoder().encode(state)
    #expect(SceneModel.restoredState(from: data) == state)
    #expect(SceneModel.restoredState(from: Data("garbage".utf8)) == SceneState())
    #expect(SceneModel.restoredState(from: nil) == SceneState())
}

@MainActor @Test func aStoredStateWithoutThePanelFieldReadsAsClosed() {
    let stored = Data(
        #"{"threadID":"e5f6g7h8","focusedPane":"chat","isDrawerOpen":true,"isNewThreadOpen":false,"filter":"mine"}"#
            .utf8)
    let state = SceneModel.restoredState(from: stored)
    #expect(state.threadID == "e5f6g7h8")
    #expect(!state.isOverviewPanelOpen)
}

@MainActor @Test func resettingTheWindowForAnotherInstanceStartsOver() {
    let first = UUID()
    let scene = SceneModel(
        state: SceneState(instanceID: first, threadID: "a1b2c3d4", isDrawerOpen: true, filter: .everyone))
    scene.applyColdStart(.thread("a1b2c3d4"))
    scene.justPairedUserName = "Test Person"
    let second = UUID()
    scene.reset(instanceID: second)
    #expect(scene.state == SceneState(instanceID: second))
    #expect(!scene.hasAppliedColdStart)
    #expect(scene.justPairedUserName == "Test Person")
}

@MainActor @Test func isShownFollowsTheFocusedPane() {
    let scene = SceneModel(state: SceneState())
    #expect(scene.isShown(.chat))
    #expect(!scene.isShown(.files))
    scene.focus(pane: .files)
    #expect(scene.isShown(.files))
    #expect(!scene.isShown(.chat))
}

// MARK: Two panes and several windows

private func sideBySideInputs() -> LayoutInputs {
    LayoutInputs(isCompactWidth: false, isCompactHeight: false, detailWidth: 900, hasVerticalToolbarEdge: false)
}

@MainActor @Test func aStoredStateWithoutTheNewFieldsDecodes() {
    let stored = Data(#"{"threadID":"e5f6g7h8","focusedPane":"chat","isDrawerOpen":true,"filter":"mine"}"#.utf8)
    let state = SceneModel.restoredState(from: stored)
    #expect(state.secondPane == nil && !state.secondPaneLeads)
    #expect(state.stackedFraction == 0.5 && state.instanceID == nil)
}

@MainActor @Test func theNewFieldsRoundTrip() throws {
    let state = SceneState(
        instanceID: UUID(), threadID: "e5f6g7h8", focusedPane: .preview, secondPane: .chat, secondPaneLeads: true,
        stackedFraction: 0.6)
    let data = try JSONEncoder().encode(state)
    #expect(SceneModel.restoredState(from: data) == state)
}

@MainActor @Test func aSceneForAnotherInstanceDropsItsThread() {
    let state = SceneState(instanceID: UUID(), threadID: "e5f6g7h8", secondPane: .preview)
    let scene = SceneModel(state: state, request: nil, activeInstance: UUID())
    #expect(scene.state.threadID == nil && scene.state.secondPane == nil)
    #expect(scene.state.isDrawerOpen)
    let same = UUID()
    let kept = SceneModel(
        state: SceneState(instanceID: same, threadID: "e5f6g7h8"), request: nil, activeInstance: same)
    #expect(kept.state.threadID == "e5f6g7h8")
}

@MainActor @Test func aRequestedWindowSkipsColdStart() {
    let active = UUID()
    let scene = SceneModel(
        state: SceneState(), request: .thread(instance: active, id: "d0e515ef"), activeInstance: active)
    #expect(scene.state.threadID == "d0e515ef")
    scene.applyColdStart(.newThread)
    #expect(scene.state.threadID == "d0e515ef" && !scene.state.isNewThreadOpen)
}

@MainActor @Test func aRequestForAnotherInstanceOpensTheDrawer() {
    let scene = SceneModel(
        state: SceneState(), request: .thread(instance: UUID(), id: "d0e515ef"), activeInstance: UUID())
    #expect(scene.state.threadID == nil && scene.state.isDrawerOpen)
}

@MainActor @Test func aBlankWindowOpensNoThread() {
    let scene = SceneModel(state: SceneState(threadID: "d0e515ef"), request: .blank(UUID()), activeInstance: UUID())
    #expect(scene.state.threadID == nil && scene.state.isDrawerOpen)
    #expect(scene.windowValue == nil)
}

@MainActor @Test func isShownFollowsThePlan() {
    let scene = SceneModel(state: SceneState())
    let panes: [PaneID] = [.chat, .preview, .files]
    let state = PaneState(focused: .chat, second: .preview)
    scene.updatePlan(PaneLayoutRules.plan(state, available: panes, inputs: sideBySideInputs()), ownedBy: "t")
    #expect(scene.isShown(.chat) && scene.isShown(.preview))
    #expect(!scene.isShown(.files))
}

@MainActor @Test func pushStillFocusesFiles() {
    let scene = SceneModel(state: SceneState(focusedPane: .chat, secondPane: .files))
    scene.focus(pane: .files)
    #expect(scene.state.focusedPane == .files)
    #expect(scene.paneState.focused == .files && scene.paneState.second == nil)
}

@MainActor @Test func anUnknownPaneNameKeepsTheThread() {
    let stored = Data(
        #"{"threadID":"e5f6g7h8","focusedPane":"files","secondPane":"whiteboard","lastSecondPane":"whiteboard"}"#.utf8)
    let state = SceneModel.restoredState(from: stored)
    #expect(state.threadID == "e5f6g7h8" && state.focusedPane == .files)
    #expect(state.secondPane == nil)
}

@MainActor @Test func anOversizedStoredSceneIsIgnored() {
    let json = #"{"threadID":"e5f6g7h8"}"#
    func padded(to size: Int) -> Data { Data((json + String(repeating: " ", count: size - json.utf8.count)).utf8) }
    #expect(SceneModel.restoredState(from: padded(to: 4097)) == SceneState())
    #expect(SceneModel.restoredState(from: padded(to: 4096)).threadID == "e5f6g7h8")
}

@MainActor @Test func aWindowThatChangesThreadUpdatesItsValue() {
    let active = UUID()
    let scene = SceneModel(state: SceneState(), request: nil, activeInstance: active)
    scene.open(thread: "d0e515ef", isCompactWidth: false)
    #expect(scene.windowValue == .thread(instance: active, id: "d0e515ef"))
    scene.open(thread: "a1b2c3d4", isCompactWidth: false)
    #expect(scene.windowValue == .thread(instance: active, id: "a1b2c3d4"))
    scene.closeThread()
    #expect(scene.windowValue == nil)
}

@MainActor @Test func applyWritesThePaneFields() {
    let scene = SceneModel(state: SceneState())
    scene.apply(PaneState(focused: .preview, second: .chat, secondLeads: true, stackedFraction: 9))
    #expect(scene.state.focusedPane == .preview && scene.state.secondPane == .chat)
    #expect(scene.state.stackedFraction == 0.7)
}

@MainActor @Test func aTaskLinkForAnUnpairedAddressAsksToPair() {
    let active = instance("https://yak.example.com")
    let scene = SceneModel(state: SceneState())
    let link = DeepLink.task(instance: URL(string: "https://evil.example.com")!, id: "12345")
    let outcome = scene.handle(link, active: active, instances: [active], isCompactWidth: true)
    #expect(outcome == .unknownInstance(URL(string: "https://evil.example.com")!))
}

@MainActor @Test func aTaskLinkForAnotherPairedInstanceSwitchesToIt() {
    let active = instance("https://yak.example.com")
    let other = instance("https://other.example.com")
    let scene = SceneModel(state: SceneState())
    let link = DeepLink.task(instance: URL(string: "https://other.example.com")!, id: "k3m9p2qa")
    let outcome = scene.handle(link, active: active, instances: [active, other], isCompactWidth: true)
    #expect(
        outcome
            == .switchInstanceThenOpenTaskPage(other, URL(string: "https://other.example.com/tasks/k3m9p2qa")!))
}

private func compactInputs() -> LayoutInputs {
    LayoutInputs(isCompactWidth: true, isCompactHeight: false, detailWidth: 400, hasVerticalToolbarEdge: false)
}

@MainActor private func sceneWithOverviewAsPane(thread: String = "d0e515ef") -> SceneModel {
    let scene = SceneModel(state: SceneState(threadID: thread, focusedPane: .preview))
    scene.updatePlan(
        PaneLayoutRules.plan(
            PaneState(focused: .preview), available: [.chat, .preview, .overview], inputs: compactInputs()),
        ownedBy: "t")
    return scene
}

@MainActor @Test func commandFiveRecordsThePaneItLeavesAndTheTitleReturnsThere() {
    let scene = sceneWithOverviewAsPane()
    scene.commandFocus(.overview)
    #expect(scene.state.focusedPane == .overview)
    scene.updatePlan(
        PaneLayoutRules.plan(scene.paneState, available: [.chat, .preview, .overview], inputs: compactInputs()),
        ownedBy: "t")
    scene.toggleOverview()
    #expect(scene.state.focusedPane == .preview)
}

@MainActor @Test func theOverviewReturnPaneResetsWithTheThread() {
    let scene = sceneWithOverviewAsPane()
    scene.commandFocus(.overview)
    scene.open(thread: "a1b2c3d4", isCompactWidth: true)
    scene.focus(pane: .overview)
    scene.updatePlan(
        PaneLayoutRules.plan(PaneState(focused: .overview), available: [.chat, .overview], inputs: compactInputs()),
        ownedBy: "t")
    scene.toggleOverview()
    #expect(scene.state.focusedPane == .chat)
}

@MainActor @Test func aRestoredWindowKeepsItsPanesWhenTheRequestNamesItsThread() {
    let active = UUID()
    let restored = SceneState(
        instanceID: active, threadID: "d0e515ef", focusedPane: .preview, isDrawerOpen: false, secondPane: .chat,
        behindPane: .files)
    let kept = SceneModel(
        state: restored, request: .thread(instance: active, id: "d0e515ef"), activeInstance: active)
    #expect(kept.state.focusedPane == .preview && kept.state.secondPane == .chat && kept.state.behindPane == .files)
    #expect(kept.hasAppliedColdStart)
    let other = SceneModel(
        state: restored, request: .thread(instance: active, id: "a1b2c3d4"), activeInstance: active)
    #expect(other.state.focusedPane == .chat && other.state.secondPane == nil && other.state.behindPane == nil)
}

@MainActor @Test func theOldScreensDisappearDoesNotClearTheNewThreadsPlan() {
    let scene = SceneModel(state: SceneState(threadID: "d0e515ef", focusedPane: .chat))
    let first = PaneLayoutRules.plan(PaneState(focused: .chat), available: [.chat, .preview], inputs: compactInputs())
    scene.updatePlan(first, ownedBy: "d0e515ef")
    scene.updatePlan(first, ownedBy: "a1b2c3d4")
    scene.clearPlan(ownedBy: "d0e515ef")
    #expect(scene.plan != nil)
    scene.clearPlan(ownedBy: "a1b2c3d4")
    #expect(scene.plan == nil)
}

@MainActor @Test func theOverviewReturnsToChatWhenThePaneBeforeIsGone() {
    let scene = sceneWithOverviewAsPane()
    scene.commandFocus(.overview)
    scene.updatePlan(
        PaneLayoutRules.plan(PaneState(focused: .overview), available: [.chat, .overview], inputs: compactInputs()),
        ownedBy: "t")
    scene.toggleOverview()
    #expect(scene.state.focusedPane == .chat)
}

@MainActor @Test func aClearOpensItsNewThreadOnlyFromTheThreadItWasSentFrom() {
    let scene = SceneModel(state: SceneState(threadID: "d0e515ef", focusedPane: .files))
    scene.openReplacement("n3w7hr3d", sentFrom: "d0e515ef", isCompactWidth: true)
    #expect(scene.state.threadID == "n3w7hr3d")
    #expect(scene.state.focusedPane == .chat)
}

@MainActor @Test func aClearDeliveredWhileLookingAtAnotherThreadMovesNothing() {
    let scene = SceneModel(state: SceneState(threadID: "a1b2c3d4", focusedPane: .files))
    scene.openReplacement("n3w7hr3d", sentFrom: "d0e515ef", isCompactWidth: true)
    #expect(scene.state.threadID == "a1b2c3d4")
    #expect(scene.state.focusedPane == .files)
    let none = SceneModel(state: SceneState())
    none.openReplacement("n3w7hr3d", sentFrom: "d0e515ef", isCompactWidth: true)
    #expect(none.state.threadID == nil)
}

@MainActor private func wideScene(second: PaneID? = nil) -> SceneModel {
    let scene = SceneModel(state: SceneState(secondPane: second))
    let state = scene.paneState
    let inputs = sideBySideInputs()
    scene.updatePlan(
        PaneLayoutRules.plan(state, available: [.chat, .preview, .terminal, .overview], inputs: inputs),
        inputs: inputs, ownedBy: "t")
    return scene
}

@MainActor @Test func aWideWindowOffersTheSecondPaneAndShowsOnePaneByDefault() {
    let scene = wideScene()
    #expect(scene.plan?.slots.count == 1)
    let choices = scene.secondChoices
    #expect(choices?.isOffered == true && choices?.current == nil)
    #expect(choices?.panes == [.preview, .terminal, .overview])
}

@MainActor @Test func aNarrowWindowOffersNoSecondPane() {
    let scene = SceneModel(state: SceneState())
    let inputs = compactInputs()
    scene.updatePlan(
        PaneLayoutRules.plan(scene.paneState, available: [.chat, .preview], inputs: inputs), inputs: inputs,
        ownedBy: "t")
    #expect(scene.secondChoices?.isOffered == false)
}

@MainActor @Test func pickingASecondPaneOpensItAndNoneClosesIt() {
    let scene = wideScene()
    scene.setSecond(.preview)
    #expect(scene.state.secondPane == .preview && scene.state.focusedPane == .chat)
    scene.setSecond(nil)
    #expect(scene.state.secondPane == nil)
}

@MainActor @Test func theSecondPaneChoiceIsStoredAndRestored() throws {
    let scene = wideScene()
    scene.setSecond(.terminal)
    let restored = SceneModel.restoredState(from: scene.encodedState)
    #expect(restored.secondPane == .terminal)
}

@MainActor @Test func aStateStoredBeforeTheChoiceDropsTheAppsSecondPane() {
    let stored = Data(#"{"threadID":"e5f6g7h8","focusedPane":"chat","secondPane":"preview"}"#.utf8)
    #expect(SceneModel.restoredState(from: stored).secondPane == nil)
}

@MainActor @Test func aRelaunchedWindowOfTheSameInstanceKeepsTheSecondPane() {
    let instance = UUID()
    let scene = SceneModel(state: SceneState(instanceID: instance, threadID: "d0e515ef", secondPane: .terminal))
    let relaunched = SceneModel(
        state: SceneModel.restoredState(from: scene.encodedState), request: nil, activeInstance: instance)
    #expect(relaunched.state.threadID == "d0e515ef" && relaunched.state.secondPane == .terminal)
}
