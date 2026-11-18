import Foundation
import Observation
import YakKit

/// The share form of one thread: who may read it. The form starts from the server's choice and keeps the person's
/// choice through a refusal or a lost answer.
@MainActor @Observable
final class ShareModel {
    static let slowDelay: Duration = .seconds(8)
    static let skeletonDelay: Duration = .milliseconds(300)

    let threadID: String
    private(set) var form: ShareForm?
    private(set) var load: ThreadLoadState = .loading
    private(set) var showsSkeleton = false
    var search = ""
    private(set) var isSaving = false
    /// A refusal or failure, above the choices.
    private(set) var problem: String?
    /// Files that are never shared, as the server lists them.
    private(set) var secretFiles: [String] = []
    /// Counts refusals and failures, for the error haptic.
    private(set) var refusalCount = 0
    /// Runs after a save went through, so the thread's page shows the new visibility.
    var onSaved: (@MainActor () async -> Void)?

    private let actions: OverviewActions
    private let clock: any YakClock
    private var timerTask: Task<Void, Never>?

    init(threadID: String, actions: OverviewActions, clock: any YakClock) {
        self.threadID = threadID
        self.actions = actions
        self.clock = clock
    }

    /// The people to list for the search text; the search shows only past eight people.
    var visiblePeople: [SharePerson] { form?.filtered(by: search) ?? [] }
    var showsSearch: Bool { (form?.people.count ?? 0) > 8 }
    /// Only the owner is on this Yak.
    var hasNobodyElse: Bool { (form?.people.filter { form?.isSelectable($0) == true }.count ?? 0) == 0 }
    var canSave: Bool { (form?.canSave ?? false) && !isSaving }

    func start() async {
        guard form == nil, load != .ready else { return }
        await fetch()
    }

    func retry() async {
        await fetch()
    }

    private func fetch() async {
        load = .loading
        timerTask?.cancel()
        timerTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.skeletonDelay) } catch { return }
            self?.showsSkeleton = true
            do { try await clock.sleep(for: Self.slowDelay) } catch { return }
            if self?.load == .loading { self?.load = .slow }
        }
        do {
            let share = try await actions.share(threadID)
            form = ShareForm(share: share)
            secretFiles = share.secretFiles
            load = .ready
        } catch {
            load = .failed(ThreadProblem(error))
        }
        timerTask?.cancel()
        showsSkeleton = false
    }

    func setMode(_ mode: ShareMode) {
        guard !isSaving else { return }
        form?.mode = mode
    }

    func toggle(_ id: LenientID) {
        guard !isSaving else { return }
        form?.toggle(id)
    }

    /// Sends the choice. True when the sheet should close.
    func save() async -> Bool {
        guard let form, form.canSave, !isSaving else { return false }
        problem = nil
        isSaving = true
        let request = form.request
        let outcome = await actions.updateShare(threadID, mode: request.mode, userIDs: request.userIDs)
        isSaving = false
        switch outcome {
        case .done, .alreadySent:
            await onSaved?()
            return true
        case .refused(_, let message, _, _):
            refusalCount += 1
            problem = message
        case .failed(let failure):
            refusalCount += 1
            problem =
                failure == .offline
                ? String(localized: "share.notSaved") : OverviewModel.sentence(for: failure)
        }
        return false
    }

    func stop() {
        timerTask?.cancel()
    }
}
