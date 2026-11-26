import Foundation
import Observation
import YakKit

extension Notification.Name {
    /// A thread was deleted on this device: the Spotlight index is written again without it.
    static let yakThreadRemoved = Notification.Name("yak.threadRemoved")
}

/// What a person is asked to confirm before a thread is closed or deleted.
struct ThreadActionConfirmation: Identifiable, Equatable {
    enum Kind: Equatable { case close, delete }

    let id = UUID()
    let kind: Kind
    /// Where the person asked: the dialog shows on that screen only.
    let surface: ThreadActionSubject.Surface
    let threadID: String
    /// All sub-threads below the thread, which close or go with it.
    let subThreadCount: Int
    /// What stays on GitHub; known only for a thread whose page the device has seen.
    let branch: String?
    let pullRequestNumber: Int?

    /// The system sheet's title.
    var title: String {
        switch kind {
        case .close: String(localized: "lifecycle.close.title")
        case .delete: String(localized: "lifecycle.delete.title")
        }
    }

    /// Says plainly what happens: a closed thread cannot be reopened; a deleted one is gone for good, and the branch
    /// and pull request on GitHub stay.
    var message: String {
        switch kind {
        case .close:
            subThreadCount > 0
                ? String(localized: "lifecycle.close.messageWithChildren \(subThreadCount)")
                : String(localized: "lifecycle.close.message")
        case .delete:
            [removed, stays].joined(separator: " ")
        }
    }

    private var removed: String {
        subThreadCount > 0
            ? String(localized: "lifecycle.delete.removesWithChildren \(subThreadCount)")
            : String(localized: "lifecycle.delete.removes")
    }

    private var stays: String {
        switch (branch, pullRequestNumber) {
        case (let branch?, let number?):
            String(localized: "lifecycle.delete.staysBranchAndPull \(branch) \(String(number))")
        case (let branch?, nil): String(localized: "lifecycle.delete.staysBranch \(branch)")
        case (nil, let number?): String(localized: "lifecycle.delete.staysPull \(String(number))")
        case (nil, nil): String(localized: "lifecycle.delete.stays")
        }
    }

    var confirmLabel: String {
        switch kind {
        case .close: String(localized: "lifecycle.close.confirm")
        case .delete: String(localized: "thread.menu.delete")
        }
    }
}

/// A sentence the screens show for a few seconds: "Thread deleted." or why an action did not happen.
struct ThreadActionNotice: Equatable, Identifiable {
    let id = UUID()
    let text: String
    let isFailure: Bool
    /// The screen that shows it.
    let surface: ThreadActionSubject.Surface
}

/// Star, close and delete for one window: starts the requests, keeps the confirmation, the notice and the delete
/// failure alert, and tells the shell when the thread it shows is gone.
@MainActor @Observable
final class ThreadActionsModel {
    /// How long a notice stays; a UI test reads it for longer, so a slow machine still finds it.
    static var noticeLifetime: Duration {
        #if DEBUG
            if TestMode.current() != nil { return .seconds(30) }
        #endif
        return .seconds(4)
    }

    private(set) var state = ThreadLifecycleState()
    /// A star the person just set, shown before the server has answered.
    private(set) var starOverrides: [String: Bool] = [:]
    private(set) var notice: ThreadActionNotice?
    /// Set while the person decides about a close or a delete.
    var confirmation: ThreadActionConfirmation?
    /// The delete that the server refused because a sandbox could not be destroyed.
    var deleteFailure: DeleteFailure?
    /// Counts the star and close changes of each thread, so its screen reads the page again for every one, however
    /// many land in one tick.
    private(set) var versions: [String: Int] = [:]
    /// Plays a success or a warning for a finished action; replaced in tests.
    @ObservationIgnored var playHaptic: @MainActor (Bool) -> Void = { Haptic.playCardAnswered(isOK: $0) }

    struct DeleteFailure: Equatable {
        let threadID: String
        let message: String
        let surface: ThreadActionSubject.Surface
    }

    @ObservationIgnored private let session: InstanceSession
    @ObservationIgnored private let clock: any YakClock
    /// Asked whether a thread is the one the window shows, so a deleted thread is left.
    @ObservationIgnored var isThreadOpen: (String) -> Bool = { _ in false }
    /// The window leaves the thread it shows: the shell opens the drawer.
    @ObservationIgnored var leaveThread: (String) -> Void = { _ in }
    /// A star, a close or a removal changed what the device shows; `removed` lists the threads that are gone, sub-threads
    /// included. The shell clears their notifications, writes the Spotlight index again and reloads the widgets.
    @ObservationIgnored var threadsChanged: (_ removed: [String]) async -> Void = { _ in }
    @ObservationIgnored private var isAsking = false
    @ObservationIgnored private var noticeTask: Task<Void, Never>?
    @ObservationIgnored private var initiated: Set<String> = []
    /// The leaving of a thread says so in the drawer, which is where the window goes.

    init(session: InstanceSession, clock: any YakClock) {
        self.session = session
        self.clock = clock
    }

    func isDeleting(_ threadID: String) -> Bool { state.isDeleting(threadID) }
    func isBusy(_ threadID: String) -> Bool { state.isBusy(threadID) }
    func isClosing(_ threadID: String) -> Bool { state.running[threadID]?.contains(.close) == true }
    /// A close or a delete runs: the row shows a spinner. A star does not.
    func isWorking(_ threadID: String) -> Bool { isDeleting(threadID) || isClosing(threadID) }

    func version(of threadID: String) -> Int { versions[threadID] ?? 0 }

    /// The page has the star the person set, so their own value is not needed any more.
    func clearStarOverride(_ threadID: String) { starOverrides[threadID] = nil }

    /// Whether a lifecycle change concerns a thread the window shows, the thread itself or a sub-thread of a removed one.
    static func removedThreads(in change: ThreadLifecycleChange) -> [String] {
        switch change {
        case .deleted(let id, let subThreadIDs), .gone(let id, let subThreadIDs): [id] + subThreadIDs
        case .starred, .closed: []
        }
    }

    /// The star the person sees: their own tap until the answer is in, the page's value after.
    func isStarred(_ threadID: String, page: Bool) -> Bool { starOverrides[threadID] ?? page }

    // MARK: Following the session

    /// Follows the requests and their results until the calling task ends.
    func run() async {
        guard let lifecycle = await session.threadLifecycle() else { return }
        async let states: Void = followStates(lifecycle)
        async let changes: Void = followChanges(lifecycle)
        _ = await (states, changes)
    }

    private func followStates(_ lifecycle: ThreadLifecycle) async {
        for await next in await lifecycle.states() { state = next }
    }

    private func followChanges(_ lifecycle: ThreadLifecycle) async {
        for await change in await lifecycle.changes() {
            let removed = Self.removedThreads(in: change)
            switch change {
            case .deleted(let id, _):
                let wasOpen = removed.contains(where: isThreadOpen)
                if wasOpen { leaveThread(id) }
                if initiated.remove(id) != nil || wasOpen {
                    say(String(localized: "lifecycle.deleted"), failure: false, surface: .row)
                }
            case .gone(let id, _):
                let wasOpen = removed.contains(where: isThreadOpen)
                if wasOpen { leaveThread(id) }
                if initiated.remove(id) != nil || wasOpen {
                    say(String(localized: "lifecycle.gone"), failure: true, surface: .row)
                }
            case .starred(let id, _), .closed(let id):
                versions[id, default: 0] += 1
            }
            await threadsChanged(removed)
        }
    }

    // MARK: Asking

    /// Builds the confirmation for a close or a delete. The branch and the pull request come from the saved page of
    /// the thread, when the device has one.
    func ask(
        _ kind: ThreadActionConfirmation.Kind, from surface: ThreadActionSubject.Surface, threadID: String,
        subThreadCount: Int, show: ThreadShow? = nil
    ) {
        // A second question waits for the first.
        guard confirmation == nil, !isAsking else { return }
        isAsking = true
        Task {
            defer { isAsking = false }
            let known: ThreadShow?
            if let show {
                known = show
            } else if let cache = await session.instanceCache() {
                known = await cache.thread(threadID)?.show
            } else {
                known = nil
            }
            confirmation = ThreadActionConfirmation(
                kind: kind, surface: surface, threadID: threadID, subThreadCount: subThreadCount,
                branch: known?.thread.branch,
                pullRequestNumber: known?.pullRequest?.number)
        }
    }

    func confirm(_ confirmation: ThreadActionConfirmation) {
        self.confirmation = nil
        switch confirmation.kind {
        case .close: close(confirmation.threadID, from: confirmation.surface)
        case .delete: delete(confirmation.threadID, from: confirmation.surface)
        }
    }

    // MARK: Doing

    func setStar(_ threadID: String, to isStarred: Bool, from surface: ThreadActionSubject.Surface) {
        guard !state.isBusy(threadID) else { return }
        starOverrides[threadID] = isStarred
        Task {
            guard let lifecycle = await session.threadLifecycle() else {
                finishStar(threadID, outcome: .failed(.offline), surface: surface)
                return
            }
            finishStar(threadID, outcome: await lifecycle.setStar(threadID, to: isStarred), surface: surface)
        }
    }

    private func finishStar(
        _ threadID: String, outcome: ThreadLifecycleOutcome, surface: ThreadActionSubject.Surface
    ) {
        // After a success the page may still hold the old star, so the person's value stays until the page is read
        // again; a failure, or a thread no screen shows, drops it at once.
        if case .failed(let failure) = outcome {
            starOverrides[threadID] = nil
            report(failure, action: .star, surface: surface)
        } else if !isThreadOpen(threadID) {
            starOverrides[threadID] = nil
        }
    }

    func close(_ threadID: String, from surface: ThreadActionSubject.Surface) {
        guard !state.isBusy(threadID) else { return }
        Task {
            guard let lifecycle = await session.threadLifecycle() else {
                return report(.offline, action: .close, surface: surface)
            }
            let outcome = await lifecycle.close(threadID)
            if case .failed(let failure) = outcome {
                report(failure, action: .close, surface: surface)
            } else {
                playHaptic(true)
            }
        }
    }

    func delete(_ threadID: String, from surface: ThreadActionSubject.Surface) {
        guard !state.isBusy(threadID) else { return }
        initiated.insert(threadID)
        deleteFailure = nil
        Task {
            guard let lifecycle = await session.threadLifecycle() else {
                initiated.remove(threadID)
                return report(.offline, action: .delete, surface: surface)
            }
            let outcome = await lifecycle.delete(threadID)
            switch outcome {
            case .done: playHaptic(true)
            case .failed(.sandboxUnavailable(let message)):
                initiated.remove(threadID)
                playHaptic(false)
                deleteFailure = DeleteFailure(threadID: threadID, message: message, surface: surface)
            case .failed(.gone):
                break
            case .failed(let failure):
                initiated.remove(threadID)
                report(failure, action: .delete, surface: surface)
            }
        }
    }

    /// The page or the engine learned that the thread is gone.
    func threadIsGone(_ threadID: String) {
        initiated.insert(threadID)
        Task { await session.threadLifecycle()?.noteGone(threadID) }
    }

    // MARK: Saying

    private func report(
        _ failure: ThreadLifecycleFailure, action: ThreadLifecycleAction, surface: ThreadActionSubject.Surface
    ) {
        playHaptic(false)
        say(Self.sentence(for: failure, action: action), failure: true, surface: surface)
    }

    /// Each failure in words from the String Catalog; the server's own sentence only for a refused delete.
    static func sentence(for failure: ThreadLifecycleFailure, action: ThreadLifecycleAction) -> String {
        switch failure {
        case .offline: String(localized: "lifecycle.offline")
        case .unauthorized: String(localized: "lifecycle.unauthorized")
        case .forbidden: String(localized: "lifecycle.forbidden")
        case .gone: String(localized: "lifecycle.gone")
        case .sandboxUnavailable(let message): message
        case .failed:
            switch action {
            case .star: String(localized: "lifecycle.failed.star")
            case .close: String(localized: "lifecycle.failed.close")
            case .delete: String(localized: "lifecycle.failed.delete")
            }
        }
    }

    private func say(_ text: String, failure: Bool, surface: ThreadActionSubject.Surface) {
        let next = ThreadActionNotice(text: text, isFailure: failure, surface: surface)
        notice = next
        noticeTask?.cancel()
        noticeTask = Task { [clock] in
            do { try await clock.sleep(for: Self.noticeLifetime) } catch { return }
            if self.notice?.id == next.id { self.notice = nil }
        }
    }

    func dismissNotice() { notice = nil }
}
