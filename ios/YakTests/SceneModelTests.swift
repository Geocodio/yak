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
