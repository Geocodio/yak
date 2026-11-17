import AVKit
import UIKit
import YakKit

/// The one `AVPlayerViewController`, its picture in picture settings and its delegate.
@MainActor
final class SystemPlayerHosting: NSObject, PlayerControllerHosting, @preconcurrency AVPlayerViewControllerDelegate {
    let controller = AVPlayerViewController()
    weak var playback: WalkthroughPlayback?

    var viewController: UIViewController { controller }

    override init() {
        super.init()
        controller.allowsPictureInPicturePlayback = true
        controller.canStartPictureInPictureAutomaticallyFromInline = true
        controller.updatesNowPlayingInfoCenter = false
        controller.entersFullScreenWhenPlaybackBegins = false
        controller.delegate = self
    }

    func detachFromCurrentParent() {
        guard controller.parent != nil else { return }
        controller.willMove(toParent: nil)
        controller.view.removeFromSuperview()
        controller.removeFromParent()
    }

    func playerViewControllerWillStartPictureInPicture(_ playerViewController: AVPlayerViewController) {
        playback?.pictureInPictureStarted()
    }

    func playerViewControllerDidStartPictureInPicture(_ playerViewController: AVPlayerViewController) {
        playback?.pictureInPictureStarted()
    }

    func playerViewControllerDidStopPictureInPicture(_ playerViewController: AVPlayerViewController) {
        playback?.pictureInPictureStopped()
    }

    func playerViewController(
        _ playerViewController: AVPlayerViewController,
        restoreUserInterfaceForPictureInPictureStopWithCompletionHandler completionHandler: @escaping (Bool) -> Void
    ) {
        guard let playback else {
            completionHandler(false)
            return
        }
        playback.restoreUserInterface(completion: completionHandler)
    }
}

/// `AVPlayer` for one walkthrough video. Reads the item's status and error chain when asked.
@MainActor
final class SystemWalkthroughEngine: WalkthroughPlayerEngine {
    var onFinished: (@MainActor () -> Void)?
    private let player = AVPlayer()
    private let hosting: SystemPlayerHosting
    private var item: AVPlayerItem?
    private var finishObserver: (any NSObjectProtocol)?
    /// The error log's last HTTP status, read once after the item failed. `.some(nil)` is read and empty.
    private var httpStatus: Int??
    private var isFetchingStatus = false

    init(hosting: SystemPlayerHosting) {
        self.hosting = hosting
        hosting.controller.player = player
    }

    func load(_ url: URL) {
        let next = AVPlayerItem(url: url)
        item = next
        httpStatus = nil
        isFetchingStatus = false
        finishObserver = NotificationCenter.default.addObserver(
            forName: AVPlayerItem.didPlayToEndTimeNotification, object: next, queue: .main
        ) { [weak self] _ in
            MainActor.assumeIsolated { self?.onFinished?() }
        }
        player.replaceCurrentItem(with: next)
    }

    func play() { player.play() }
    func pause() { player.pause() }

    func stop() {
        player.pause()
        player.replaceCurrentItem(with: nil)
        if let finishObserver { NotificationCenter.default.removeObserver(finishObserver) }
        finishObserver = nil
        item = nil
        if hosting.controller.player === player { hosting.controller.player = nil }
    }

    var isReady: Bool { item?.status == .readyToPlay }

    var failure: WalkthroughVideoProblem? {
        guard let item, item.status == .failed else { return nil }
        // The status of the failed request tells an expired link from other trouble, so the answer waits for it.
        guard let httpStatus else {
            fetchStatus(of: item)
            return nil
        }
        var errors: [(domain: String, code: Int)] = []
        var error = item.error as NSError?
        while let next = error, errors.count < WalkthroughVideoProblem.maximumDepth {
            errors.append((next.domain, next.code))
            error = next.userInfo[NSUnderlyingErrorKey] as? NSError
        }
        return WalkthroughVideoProblem.make(errors: errors, httpStatus: httpStatus)
    }

    private func fetchStatus(of failed: AVPlayerItem) {
        guard !isFetchingStatus else { return }
        isFetchingStatus = true
        Task { @MainActor [weak self] in
            let code: Int?
            if #available(iOS 27, *) {
                code = await failed.errorLog?.events.last?.errorStatusCode
            } else {
                code = failed.errorLog()?.events.last?.errorStatusCode
            }
            guard let self, item === failed else { return }
            httpStatus = .some(code.flatMap { $0 > 0 ? $0 : nil })
        }
    }
}

extension WalkthroughPlayback {
    /// The player of a running app: the system audio session and one `AVPlayerViewController`.
    static func system(isOnline: @escaping @MainActor () -> Bool) -> WalkthroughPlayback {
        let hosting = SystemPlayerHosting()
        let playback = WalkthroughPlayback(
            audio: SystemAudioSession(), makeEngine: { SystemWalkthroughEngine(hosting: hosting) },
            hosting: hosting, clock: SystemClock(), isOnline: isOnline)
        hosting.playback = playback
        return playback
    }
}
