import Foundation
import Observation
import YakKit

/// The "add a sub-thread" form of one parent thread. The text is saved while it is typed, so closing the sheet,
/// a refusal or a lost answer never loses it.
@MainActor @Observable
final class AddSubThreadModel {
    static let saveDelay: Duration = .milliseconds(500)
    static let slowDelay: Duration = .seconds(8)
    static let skeletonDelay: Duration = .milliseconds(300)

    let parentID: String
    /// The saved text, kind and model; every change is saved after `saveDelay`.
    var draft = SubThreadDraft() {
        didSet {
            guard draft != oldValue, !isApplyingSaved else { return }
            hasEdited = true
            scheduleSave()
        }
    }
    private(set) var stack: ThreadStack?
    private(set) var load: ThreadLoadState = .loading
    private(set) var showsSkeleton = false
    private(set) var isSending = false
    /// A refusal or failure, above the Add button.
    private(set) var problem: String?
    /// The first message per field: "name", "brief", "kind" or "model".
    private(set) var fieldErrors: [String: String] = [:]
    /// The server's code of the last refusal, for the sentence's extra button.
    private(set) var refusedCode: String?
    /// The parent the server named in a `sub_thread_cannot_spawn` refusal.
    private(set) var refusedParentID: String?
    /// Counts refusals and failures, for the error haptic.
    private(set) var refusalCount = 0

    private let actions: OverviewActions
    private let clock: any YakClock
    private var isStarted = false
    private var hasEdited = false
    private var isApplyingSaved = false
    private var saveTask: Task<Void, Never>?
    private var timerTask: Task<Void, Never>?

    init(parentID: String, actions: OverviewActions, clock: any YakClock) {
        self.parentID = parentID
        self.actions = actions
        self.clock = clock
    }

    /// An earlier add got no answer: the fields are read-only and Add sends the same key again.
    var isUnsure: Bool { draft.sendKeyIsInFlight }

    var isAddRefused: Bool { stack?.addChild.allowed == false }

    /// The server's sentence for a stack that cannot take a sub-thread, shown at the top.
    var refusalSentence: String? {
        guard let addChild = stack?.addChild, !addChild.allowed else { return nil }
        return addChild.message ?? String(localized: "addSubThread.notAllowed")
    }

    var canAdd: Bool { !isSending && !isAddRefused }

    var parentAccountName: String? { stack?.parentAccountName }

    /// The parent thread of a thread that cannot start sub-threads: the server's answer to an add, else the stack's.
    var parentToOpen: String? { refusedParentID ?? stack?.parent?.id }

    /// True when the thread is a sub-thread, by the stack or by the server's refusal, and its parent is known.
    var offersParent: Bool {
        let code = "sub_thread_cannot_spawn"
        return (refusedCode == code || stack?.addChild.error == code) && parentToOpen != nil
    }

    var modelLabel: String? { ModelChoices.label(for: draft.model, in: stack?.models ?? []) }

    /// Reads the saved draft (text typed meanwhile wins) and the stack.
    func start() async {
        guard !isStarted else { return }
        isStarted = true
        if let saved = await actions.subThreadDraft(parentID: parentID) {
            if hasEdited {
                var merged = draft
                merged.sendKey = saved.sendKey
                merged.sendKeyIsInFlight = saved.sendKeyIsInFlight
                apply(merged)
            } else {
                apply(saved)
            }
        }
        await loadStack()
    }

    /// Takes a value that came from the saved draft; it is not an edit and is not saved again.
    private func apply(_ saved: SubThreadDraft) {
        isApplyingSaved = true
        draft = saved
        isApplyingSaved = false
    }

    func retry() async {
        await loadStack()
    }

    private func loadStack() async {
        load = .loading
        timerTask?.cancel()
        timerTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.skeletonDelay) } catch { return }
            self?.showsSkeleton = true
            do { try await clock.sleep(for: Self.slowDelay) } catch { return }
            if self?.load == .loading { self?.load = .slow }
        }
        do {
            stack = try await actions.stack(parentID).stack
            load = .ready
        } catch {
            load = .failed(ThreadProblem(error))
        }
        timerTask?.cancel()
        showsSkeleton = false
    }

    /// Sends the draft. True when the sheet should close.
    func add() async -> Bool {
        guard canAdd else { return false }
        problem = nil
        refusedCode = nil
        refusedParentID = nil
        fieldErrors = [:]
        saveTask?.cancel()
        if !isUnsure, let local = draft.problem {
            fieldErrors = [local.field: Self.sentence(for: local)]
            refusalCount += 1
            return false
        }
        isSending = true
        await actions.saveSubThreadDraft(draft, parentID: parentID)
        let outcome = await actions.addSubThread(parentID: parentID, draft: draft)
        // The saved draft knows whether a key is still waiting for an answer, and whether the text is gone.
        apply(await actions.subThreadDraft(parentID: parentID) ?? SubThreadDraft())
        isSending = false
        switch outcome {
        case .added:
            return true
        case .cannotSpawn(let message, let parentID):
            refusalCount += 1
            refusedCode = "sub_thread_cannot_spawn"
            refusedParentID = parentID
            problem = message
        case .notAdded(.refused(let code, let message, let errors)):
            refusalCount += 1
            refusedCode = code
            for field in ["name", "brief", "kind", "model"] {
                if let first = errors[field]?.first { fieldErrors[field] = first }
            }
            if errors["send_key"] != nil || fieldErrors.isEmpty { problem = errors["send_key"]?.first ?? message }
        case .notAdded(.failed(let failure)):
            refusalCount += 1
            problem =
                failure == .offline
                ? String(localized: "addSubThread.offlineFailure") : OverviewModel.sentence(for: failure)
        case .notAdded:
            break
        }
        return false
    }

    /// Saves now; the sheet calls it when it closes or the app goes to the background.
    func flush() async {
        saveTask?.cancel()
        saveTask = nil
        guard hasEdited else { return }
        await actions.saveSubThreadDraft(draft.isEmpty ? nil : draft, parentID: parentID)
    }

    func stop() {
        saveTask?.cancel()
        timerTask?.cancel()
    }

    private func scheduleSave() {
        saveTask?.cancel()
        saveTask = Task { [weak self, clock] in
            do { try await clock.sleep(for: Self.saveDelay) } catch { return }
            guard let self else { return }
            await self.actions.saveSubThreadDraft(self.draft.isEmpty ? nil : self.draft, parentID: self.parentID)
        }
    }

    /// The sentence beside the field a local problem belongs to.
    static func sentence(for problem: SubThreadDraftProblem) -> String {
        switch problem {
        case .noName: String(localized: "addSubThread.problem.noName")
        case .nameTooLong: String(localized: "addSubThread.problem.nameTooLong")
        case .noBrief: String(localized: "addSubThread.problem.noBrief")
        case .briefTooLong: String(localized: "addSubThread.problem.briefTooLong")
        }
    }
}

extension SubThreadDraftProblem {
    /// The field a problem is shown under.
    var field: String {
        switch self {
        case .noName, .nameTooLong: "name"
        case .noBrief, .briefTooLong: "brief"
        }
    }
}
