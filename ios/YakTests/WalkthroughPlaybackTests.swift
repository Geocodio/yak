import Foundation
import Testing
import UIKit
import YakKit

@testable import Yak

@MainActor private final class CallLog {
    var calls: [String] = []
}

@MainActor private final class FakeAudioSession: AudioSessionControl {
    let log: CallLog
    var activationFails = false
    init(_ log: CallLog) { self.log = log }
    func activatePlayback() throws {
        log.calls.append("activate")
        if activationFails { throw URLError(.unknown) }
    }
    func deactivate() { log.calls.append("deactivate") }
}

@MainActor private final class FakeEngine: WalkthroughPlayerEngine {
    let log: CallLog
    var onFinished: (@MainActor () -> Void)?
    var isReady = false
    var failure: WalkthroughVideoProblem?
    init(_ log: CallLog) { self.log = log }
    func load(_ url: URL) { log.calls.append("load") }
    func play() { log.calls.append("play") }
    func pause() { log.calls.append("pause") }
    func stop() { log.calls.append("stop") }
}

@MainActor private final class FakeHosting: PlayerControllerHosting {
    let log: CallLog
    let viewController = UIViewController()
    init(_ log: CallLog) { self.log = log }
    func detachFromCurrentParent() { log.calls.append("detach") }
}

@MainActor private final class Rig {
    let log = CallLog()
    let clock = ManualClock()
    var engines: [FakeEngine] = []
    let audio: FakeAudioSession
    let playback: WalkthroughPlayback
    let box = EngineBox()
    let sceneA = SceneModel(state: SceneState())
    let sceneB = SceneModel(state: SceneState())
    let home = Instance(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person", lastUsedAt: Date())
    let video = URL(string: "https://yak.example.com/video.mp4")!
    var engine: FakeEngine { engines[engines.count - 1] }

    init() {
        let log = log
        let box = box
        audio = FakeAudioSession(log)
        playback = WalkthroughPlayback(
            audio: audio,
            makeEngine: {
                let next = FakeEngine(log)
                box.engines.append(next)
                return next
            }, hosting: FakeHosting(log), clock: clock, isOnline: { box.online })
    }

    @MainActor final class EngineBox {
        var engines: [FakeEngine] = []
        var online = true
    }

    func play(task: String = "k3m9p2qa", from scene: SceneModel? = nil, refresh: URL? = nil) {
        playback.play(
            url: video, posterURL: nil, taskID: task, instance: home, from: scene ?? sceneA, refresh: { refresh })
        engines = box.engines
    }
}

@MainActor @Suite struct WalkthroughPlaybackTests {
    @Test func playActivatesTheSessionBeforePlaying() {
        let rig = Rig()
        rig.play()
        #expect(rig.log.calls == ["activate", "load", "play"])
        #expect(rig.playback.phase == .loading)
    }

    @Test func aSessionErrorStillPlays() {
        let rig = Rig()
        rig.audio.activationFails = true
        rig.play()
        #expect(rig.log.calls == ["activate", "load", "play"])
        #expect(rig.playback.current != nil)
    }

    @Test func aSecondVideoReplacesTheFirst() {
        let rig = Rig()
        rig.play(task: "one")
        rig.play(task: "two")
        #expect(rig.playback.current?.taskID == "two")
        #expect(rig.log.calls == ["activate", "load", "play", "stop", "load", "play"])
    }

    @Test func theSameVideoInPictureInPictureRestores() {
        let rig = Rig()
        rig.play()
        rig.playback.pictureInPictureStarted()
        #expect(rig.playback.presentedScene == nil)
        rig.play(from: rig.sceneB)
        #expect(rig.log.calls.filter { $0 == "load" }.count == 1)
        #expect(rig.playback.presentedScene == ObjectIdentifier(rig.sceneB))
    }

    @Test func pictureInPictureStartDismissesTheSheetOnly() {
        let rig = Rig()
        rig.play()
        rig.playback.pictureInPictureStarted()
        #expect(rig.playback.presentedScene == nil)
        #expect(rig.playback.current != nil)
        #expect(rig.playback.isPictureInPictureActive)
        #expect(!rig.log.calls.contains("deactivate"))
    }

    @Test func closingThePictureInPictureWindowEndsPlayback() {
        let rig = Rig()
        rig.play()
        rig.playback.pictureInPictureStarted()
        rig.playback.pictureInPictureStopped()
        #expect(rig.playback.current == nil)
    }

    @Test func restoreInAnotherSceneMovesTheController() {
        let rig = Rig()
        rig.playback.registerHost(rig.sceneA, isForeground: true)
        rig.playback.registerHost(rig.sceneB, isForeground: true)
        rig.play(from: rig.sceneA)
        rig.playback.prepareControllerForMount(in: rig.sceneA)
        rig.playback.pictureInPictureStarted()
        rig.playback.unregisterHost(rig.sceneA)
        var restored: Bool?
        rig.playback.restoreUserInterface { restored = $0 }
        #expect(restored == true)
        #expect(rig.playback.presentedScene == ObjectIdentifier(rig.sceneB))
        rig.playback.prepareControllerForMount(in: rig.sceneB)
        #expect(rig.log.calls.suffix(1) == ["detach"])
        #expect(rig.playback.mountedScene == ObjectIdentifier(rig.sceneB))
    }

    @Test func restoreGoesToTheStartingScene() {
        let rig = Rig()
        rig.playback.registerHost(rig.sceneA, isForeground: false)
        rig.playback.registerHost(rig.sceneB, isForeground: true)
        rig.play(from: rig.sceneA)
        rig.playback.pictureInPictureStarted()
        rig.playback.restoreUserInterface { _ in }
        #expect(rig.playback.presentedScene == ObjectIdentifier(rig.sceneA))
    }

    @Test func restoreWithoutAHostCloses() {
        let rig = Rig()
        rig.play()
        rig.playback.pictureInPictureStarted()
        var restored: Bool?
        rig.playback.restoreUserInterface { restored = $0 }
        #expect(restored == false)
        #expect(rig.playback.current == nil)
    }

    @Test func closeReleasesAndDeactivatesOnce() {
        let rig = Rig()
        rig.play()
        rig.playback.close()
        rig.playback.close()
        #expect(rig.playback.current == nil)
        #expect(rig.log.calls.filter { $0 == "deactivate" }.count == 1)
    }

    @Test func finishingDeactivatesOnce() {
        let rig = Rig()
        rig.play()
        rig.engine.onFinished?()
        rig.playback.close()
        #expect(rig.log.calls.filter { $0 == "deactivate" }.count == 1)
    }

    @Test func backgroundWithoutPictureInPicturePauses() {
        let rig = Rig()
        rig.play()
        rig.playback.didEnterBackground()
        rig.playback.didEnterBackground()
        #expect(rig.log.calls.filter { $0 == "pause" }.count == 2)
        #expect(rig.log.calls.filter { $0 == "deactivate" }.count == 1)
    }

    @Test func backgroundInPictureInPictureKeepsPlaying() {
        let rig = Rig()
        rig.play()
        rig.playback.pictureInPictureStarted()
        rig.playback.didEnterBackground()
        #expect(!rig.log.calls.contains("pause"))
        #expect(!rig.log.calls.contains("deactivate"))
    }

    @Test func removingTheInstanceStopsItsVideoOnly() async {
        let rig = Rig()
        rig.play()
        let other = Instance(
            url: URL(string: "https://other.example.com")!, name: "Example staging", userName: "Test person",
            lastUsedAt: Date())
        await rig.playback.instanceRemoved(other, reason: .unpaired)
        #expect(rig.playback.current != nil)
        await rig.playback.instanceRemoved(rig.home, reason: .unpaired)
        #expect(rig.playback.current == nil)
    }

    @Test func phasesFollowTheEngine() {
        let rig = Rig()
        rig.play()
        rig.clock.advance(.seconds(9))
        rig.playback.refreshPhase()
        #expect(rig.playback.phase == .slow)
        rig.engine.isReady = true
        rig.playback.refreshPhase()
        #expect(rig.playback.phase == .playing)
        rig.play(task: "other")
        rig.engine.failure = .expired
        rig.playback.refreshPhase()
        #expect(rig.playback.phase == .failed(.expired))
    }

    @Test func offlineRetriesWhenOnline() {
        let rig = Rig()
        rig.box.online = false
        rig.play()
        rig.playback.networkChanged(isOnline: false)
        #expect(rig.playback.phase == .failed(.offline))
        rig.box.online = true
        rig.playback.networkChanged(isOnline: true)
        #expect(rig.log.calls.filter { $0 == "load" }.count == 2)
        #expect(rig.playback.phase == .loading)
        rig.playback.networkChanged(isOnline: true)
        #expect(rig.log.calls.filter { $0 == "load" }.count == 2)
    }

    @Test func retryAsksForAFreshLink() async {
        let rig = Rig()
        let fresh = URL(string: "https://yak.example.com/fresh.mp4")!
        rig.play(refresh: fresh)
        rig.engine.failure = .expired
        rig.playback.refreshPhase()
        await rig.playback.retry()
        #expect(rig.playback.current?.url == fresh)
        #expect(rig.log.calls.filter { $0 == "load" }.count == 2)
    }

    @Test func aNilAnswerKeepsTheFailedPhase() async {
        let rig = Rig()
        rig.play(refresh: nil)
        rig.engine.failure = .expired
        rig.playback.refreshPhase()
        await rig.playback.retry()
        #expect(rig.playback.phase == .failed(.expired))
        #expect(rig.log.calls.filter { $0 == "load" }.count == 1)
    }

    @Test func aRefreshThatNeverAnswersTimesOut() async {
        let rig = Rig()
        rig.playback.play(
            url: rig.video, posterURL: nil, taskID: "k3m9p2qa", instance: rig.home, from: rig.sceneA,
            refresh: {
                try? await Task.sleep(for: .seconds(3_600))
                return nil
            })
        rig.box.engines.last?.failure = .expired
        rig.playback.refreshPhase()
        let retrying = Task { await rig.playback.retry() }
        while rig.clock.sleeperCount(waking: WalkthroughPlayback.refreshTimeout) == 0 { await Task.yield() }
        rig.clock.advance(WalkthroughPlayback.refreshTimeout)
        await retrying.value
        #expect(rig.playback.phase == .failed(.expired))
        #expect(rig.log.calls.filter { $0 == "load" }.count == 1)
    }
}
