import Foundation
import Observation
import UIKit
import YakKit

#if canImport(os)
    import os
#endif

/// AVPlayer behind a seam, so the tests need no media.
@MainActor
protocol WalkthroughPlayerEngine: AnyObject {
    func load(_ url: URL)
    func play()
    func pause()
    func stop()
    var isReady: Bool { get }
    /// What the item's error chain says, once it has failed.
    var failure: WalkthroughVideoProblem? { get }
    /// Called when the video plays to its end.
    var onFinished: (@MainActor () -> Void)? { get set }
}

/// What the representable does with the one shared controller, behind a seam so a test can record the order.
@MainActor
protocol PlayerControllerHosting: AnyObject {
    /// The one shared controller the player view shows.
    var viewController: UIViewController { get }
    /// `willMove(toParent: nil)`, then `removeFromParent()`, when the controller has a parent.
    func detachFromCurrentParent()
}

/// One per process. Owns the player, picture in picture and which scene shows the full player. The audio session is
/// held only while a video plays.
@MainActor @Observable
final class WalkthroughPlayback: InstanceLifecycleObserver {
    struct Item: Equatable {
        let id: UUID
        let instanceID: UUID
        let taskID: String
        var url: URL
        let posterURL: URL?
        /// The scene that started the video; the full player returns there after picture in picture.
        let startingScene: ObjectIdentifier
    }

    static let refreshTimeout: Duration = .seconds(10)
    static let pollInterval: Duration = .milliseconds(250)

    private(set) var current: Item?
    private(set) var phase: WalkthroughPlaybackPhase?
    private(set) var isPictureInPictureActive = false
    /// True while a retry waits for a fresh link; the Try again and Retry buttons are disabled meanwhile.
    private(set) var isRetrying = false
    /// The scene whose host shows the full player; nil while only the picture in picture window shows it.
    private(set) var presentedScene: ObjectIdentifier?
    private(set) var mountedScene: ObjectIdentifier?
    var controller: UIViewController { hosting.viewController }

    @ObservationIgnored private let audio: any AudioSessionControl
    @ObservationIgnored private let makeEngine: () -> any WalkthroughPlayerEngine
    @ObservationIgnored private let hosting: any PlayerControllerHosting
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private let isOnline: @MainActor () -> Bool
    @ObservationIgnored private var engine: (any WalkthroughPlayerEngine)?
    @ObservationIgnored private var refresh: (@MainActor () async -> URL?)?
    @ObservationIgnored private var isAudioActive = false
    /// The last answer from `NetworkMonitor`; the phase counts as offline when this or `isOnline` says so.
    @ObservationIgnored private var knownOnline = true
    @ObservationIgnored private var startedAt = Date()
    @ObservationIgnored private var watching: Task<Void, Never>?
    @ObservationIgnored private var hosts: [ObjectIdentifier: Bool] = [:]
    @ObservationIgnored private var lastForeground: ObjectIdentifier?

    init(
        audio: any AudioSessionControl, makeEngine: @escaping () -> any WalkthroughPlayerEngine,
        hosting: any PlayerControllerHosting, clock: any YakClock, isOnline: @escaping @MainActor () -> Bool
    ) {
        self.audio = audio
        self.makeEngine = makeEngine
        self.hosting = hosting
        self.clock = clock
        self.isOnline = isOnline
    }

    // MARK: Playing

    /// Plays `url` for that task: the same task while in picture in picture restores; another replaces the current one.
    func play(
        url: URL, posterURL: URL?, taskID: String, instance: Instance, from scene: SceneModel,
        refresh: @escaping @MainActor () async -> URL?
    ) {
        let sceneID = ObjectIdentifier(scene)
        if let current, current.taskID == taskID, current.instanceID == instance.id, isPictureInPictureActive {
            presentedScene = sceneID
            return
        }
        stopEngine()
        self.refresh = refresh
        activateAudio()
        current = Item(
            id: UUID(), instanceID: instance.id, taskID: taskID, url: url, posterURL: posterURL,
            startingScene: sceneID)
        presentedScene = sceneID
        isPictureInPictureActive = false
        startEngine(url)
    }

    private func startEngine(_ url: URL) {
        let next = makeEngine()
        next.onFinished = { [weak self] in self?.didFinishPlaying() }
        engine = next
        next.load(url)
        next.play()
        startedAt = clock.now()
        phase = .loading
        startWatching()
    }

    private func stopEngine() {
        watching?.cancel()
        watching = nil
        engine?.onFinished = nil
        engine?.stop()
        engine = nil
    }

    /// Ends playback: stops, releases the item and the controller, and gives the audio session back. Also the app
    /// lock's hook.
    func close() {
        stopEngine()
        current = nil
        phase = nil
        presentedScene = nil
        isPictureInPictureActive = false
        refresh = nil
        deactivateAudio()
    }

    private func activateAudio() {
        guard !isAudioActive else { return }
        do {
            try audio.activatePlayback()
            isAudioActive = true
        } catch {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Video")
                    .error("Audio session did not start: \(String(describing: type(of: error)), privacy: .public)")
            #endif
        }
    }

    private func deactivateAudio() {
        guard isAudioActive else { return }
        isAudioActive = false
        audio.deactivate()
    }

    // MARK: Phase

    private func startWatching() {
        watching?.cancel()
        watching = Task { [weak self, clock] in
            while !Task.isCancelled {
                guard let self, self.refreshPhase() else { return }
                do { try await clock.sleep(for: Self.pollInterval) } catch { return }
            }
        }
    }

    /// Computes the phase from the engine and the clock; false once nothing more can change it by waiting.
    @discardableResult
    func refreshPhase() -> Bool {
        guard let engine else { return false }
        let next = WalkthroughPlaybackPhase.make(
            isReady: engine.isReady, failure: engine.failure, isOnline: isOnline() && knownOnline,
            waited: .seconds(clock.now().timeIntervalSince(startedAt)))
        if phase != next { phase = next }
        switch next {
        case .playing: return false
        case .failed:
            engine.pause()
            deactivateAudio()
            return false
        case .loading, .slow: return true
        }
    }

    /// From `NetworkMonitor`. Going online reloads once when the video failed for being offline.
    func networkChanged(isOnline online: Bool) {
        knownOnline = online
        guard let current else { return }
        if online {
            guard phase == .failed(.offline) else { return }
            reload(current.url)
        } else if phase == .loading || phase == .slow {
            refreshPhase()
        }
    }

    private func reload(_ url: URL) {
        stopEngine()
        activateAudio()
        startEngine(url)
    }

    /// Asks for a new signed link (at most `refreshTimeout`), then loads it. No answer keeps the failed phase.
    func retry() async {
        guard !isRetrying, let item = current, let refresh else { return }
        isRetrying = true
        defer { isRetrying = false }
        let gate = RefreshGate()
        let fresh: URL? = await withCheckedContinuation { continuation in
            gate.continuation = continuation
            let timer = Task { [clock] in
                try? await clock.sleep(for: Self.refreshTimeout)
                gate.finish(nil)
            }
            Task { @MainActor in
                let url = await refresh()
                gate.finish(url)
                timer.cancel()
            }
        }
        guard current?.id == item.id, let fresh else { return }
        current?.url = fresh
        reload(fresh)
    }

    /// Lets the first of the answer and the timer resume the waiting caller.
    @MainActor private final class RefreshGate {
        var continuation: CheckedContinuation<URL?, Never>?
        func finish(_ url: URL?) {
            continuation?.resume(returning: url)
            continuation = nil
        }
    }

    // MARK: Picture in picture, background, end

    func pictureInPictureStarted() {
        isPictureInPictureActive = true
        presentedScene = nil
    }

    /// The window ended. With no full player showing, the person closed the window: playback ends.
    func pictureInPictureStopped() {
        isPictureInPictureActive = false
        if presentedScene == nil { close() }
    }

    func restoreUserInterface(completion: @escaping (Bool) -> Void) {
        guard let current else {
            completion(false)
            return
        }
        let target = hosts[current.startingScene] != nil ? current.startingScene : lastForeground
        guard let target, hosts[target] != nil else {
            completion(false)
            close()
            return
        }
        presentedScene = target
        completion(true)
    }

    func didEnterBackground() {
        guard current != nil, !isPictureInPictureActive, !hosts.values.contains(true) else { return }
        engine?.pause()
        deactivateAudio()
    }

    func didFinishPlaying() {
        deactivateAudio()
    }

    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {
        if current?.instanceID == instance.id { close() }
    }

    // MARK: Hosts

    func registerHost(_ scene: SceneModel, isForeground: Bool) {
        let id = ObjectIdentifier(scene)
        hosts[id] = isForeground
        if isForeground { lastForeground = id }
    }

    func unregisterHost(_ scene: SceneModel) {
        let id = ObjectIdentifier(scene)
        hosts[id] = nil
        if lastForeground == id { lastForeground = hosts.first { $0.value }?.key }
        if presentedScene == id {
            presentedScene = nil
            if !isPictureInPictureActive { close() }
        }
    }

    /// Called by `makeUIViewController` before it returns the shared controller: detaches it from any earlier
    /// parent, then records where it is mounted.
    func prepareControllerForMount(in scene: SceneModel) {
        hosting.detachFromCurrentParent()
        mountedScene = ObjectIdentifier(scene)
    }
}
