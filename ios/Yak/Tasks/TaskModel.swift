import Foundation
import Observation
import YakKit

/// One open task: reads the feed's snapshots, sends the person's decisions and actions once, and hands the screen
/// finished values. The rules for what is offered are YakKit's; this class only carries them to the views.
@MainActor @Observable
final class TaskModel: CoOwnerDecisionHost {
    /// The most refresh rounds spent on a candidate list that is not complete yet.
    static let candidateRounds = 5
    static let candidateDelay: Duration = .seconds(2)

    let taskID: String
    private(set) var task: AppTask?
    private(set) var load: ThreadLoadState = .loading
    private(set) var isOffline = false
    private(set) var problem: ThreadProblem?
    private(set) var sending: TaskAction?
    /// The last refusal or failure, shown above the decision bar.
    private(set) var message: String?
    /// The walkthrough video the player may open, or nil when there is none or its address is not safe.
    var playableVideoURL: URL? { session.playableVideoURL(task?.walkthrough?.videoUrl) }
    /// True when the refusal behind `message` is one a reload can clear (GitHub failed or the access check failed).
    private(set) var canRetryAfterMessage = false
    /// Kept across a refusal, so typed words are never lost.
    var feedback = ""
    private(set) var feedbackError: String?
    private(set) var candidates: CoOwnerCandidateList = .loading
    /// The open round of clarification questions while the viewer can answer them.
    private(set) var questionFlow: QuestionFlow?
    private(set) var isSendingAnswers = false
    /// Counts what the views answer with a haptic.
    private(set) var successCount = 0
    private(set) var errorCount = 0
    /// Set when the person's action changed the task, so the list behind it reloads.
    private(set) var didChange = false
    /// A refused decision said the account is not linked: the bar then offers the link.
    private var linkOverride: String?
    var isCompactWidth = true

    @ObservationIgnored private let session: InstanceSession
    @ObservationIgnored private let scene: SceneModel
    @ObservationIgnored private var feed: TaskDetailFeed?
    @ObservationIgnored private var readingTask: Task<Void, Never>?
    /// Counts the snapshots read, so a reload can tell its own answer arrived.
    @ObservationIgnored private var applied = 0

    init(taskID: String, session: InstanceSession, scene: SceneModel) {
        self.taskID = taskID
        self.session = session
        self.scene = scene
    }

    var decision: TaskDecisionState {
        guard let task else { return .none }
        let state = TaskDecisionState.make(task)
        if let linkOverride, case .decide = state { return .linkGitHub(connectURL: linkOverride) }
        return state
    }
    /// The GitHub connect page, only when the server's address passes the web link rule.
    var linkGitHubURL: URL? {
        guard case .linkGitHub(let connectURL) = decision else { return nil }
        return WebLinkRule.safeURL(connectURL)
    }
    var menu: [TaskMenuItem] { task.map(TaskMenu.items) ?? [] }
    var earlyWarning: EarlyWarningRow { task.map(EarlyWarningRow.make) ?? .none }
    var isSending: Bool { sending != nil }
    var isApproving: Bool { sending == .approve }
    var holdReasons: [String] { task?.coOwner?.holdReasons ?? [] }
    var repositoryName: String { task?.task.repo ?? "" }

    @discardableResult
    func decide(_ decision: CoOwnerDecision) async -> Bool {
        switch decision {
        case .approve: await perform(.approve)
        case .requestChanges(let feedback): await perform(.requestChanges(feedback: feedback))
        case .reject: await perform(.reject)
        case .withdraw: await perform(.withdraw)
        case .decline: await perform(.decline)
        case .tag(let userID): await perform(.tag(userID: userID))
        }
    }

    func start() async {
        guard feed == nil else { return }
        guard let client = await session.apiClient(), let cache = await session.instanceCache() else {
            load = .failed(.offline)
            return
        }
        let opened = TaskDetailFeed(taskID: taskID, client: client, cache: cache, clock: session.clock)
        feed = opened
        let stream = await opened.snapshots()
        readingTask = Task { [weak self] in
            for await next in stream { self?.apply(next) }
        }
        await opened.setVisible(true)
        await opened.open()
    }

    func stop() async {
        readingTask?.cancel()
        readingTask = nil
        let closing = feed
        feed = nil
        await closing?.setVisible(false)
        await closing?.close()
    }

    /// Asks for the task again and returns the answer; nil when the request failed or the screen is gone. The player
    /// uses it to get a fresh signed video link.
    func reloadTask() async -> AppTask? {
        guard let feed else { return nil }
        let before = applied
        await feed.reload()
        for _ in 0..<100 where applied == before {
            try? await session.clock.sleep(for: .milliseconds(20))
        }
        guard applied != before, case .ready = load, problem == nil, !isOffline else { return nil }
        return task
    }

    /// Hands the walkthrough video to the player.
    func playWalkthrough(with playback: WalkthroughPlayback) {
        guard let url = playableVideoURL else { return }
        let poster = session.playableVideoURL(task?.walkthrough?.posterUrl)
        playback.play(
            url: url, posterURL: poster, taskID: taskID, instance: session.instance, from: scene,
            refresh: { [weak self] in
                guard let self else { return nil }
                return session.playableVideoURL(await reloadTask()?.walkthrough?.videoUrl)
            })
    }

    func retry() async {
        message = nil
        canRetryAfterMessage = false
        await feed?.reload()
    }

    private func apply(_ next: TaskDetailFeed.Snapshot) {
        applied += 1
        task = next.task
        syncQuestions()
        load = next.load
        problem = next.problem
        isOffline = next.isOffline
        if let panel = next.task?.coOwner, panel.viewer.access != "write" || panel.status != "pending" {
            linkOverride = nil
        }
    }

    // MARK: Questions

    /// Starts a flow for a new round, from its saved draft, and drops it when the round is gone.
    private func syncQuestions() {
        guard let round = task?.questionRound, task?.canDrive == true else {
            questionFlow = nil
            return
        }
        guard questionFlow?.roundKey != round.roundKey, let feed else { return }
        questionFlow = QuestionFlow(question: round, draft: nil)
        Task { [weak self] in
            let draft = await feed.questionDraft()
            guard let self, self.questionFlow?.roundKey == round.roundKey, let draft else { return }
            self.questionFlow = QuestionFlow(question: round, draft: draft)
        }
    }

    /// Changes the flow and keeps its draft, so leaving the screen loses no answer.
    func changeQuestions(_ change: (inout QuestionFlow) -> Void) {
        guard var flow = questionFlow else { return }
        change(&flow)
        questionFlow = flow
        let draft = flow.draft
        Task { [feed] in await feed?.saveQuestionDraft(draft) }
    }

    /// Sends the reviewed answers once and reloads the task.
    func sendAnswers() async {
        guard let feed, let flow = questionFlow, flow.canSend, !isSendingAnswers, !isOffline else { return }
        isSendingAnswers = true
        message = nil
        canRetryAfterMessage = false
        await feed.saveQuestionDraft(flow.draft)
        let outcome = await feed.answerQuestions()
        isSendingAnswers = false
        switch outcome {
        case .done:
            successCount += 1
            didChange = true
            questionFlow = nil
            await feed.reload()
        case .refused(_, let text, _, _):
            errorCount += 1
            message = text
        case .failed(let failure):
            errorCount += 1
            message = Self.words(for: failure, action: nil)
        }
    }

    /// Sends the action once. Returns true when the server accepted it.
    @discardableResult
    func perform(_ action: TaskAction) async -> Bool {
        guard sending == nil, !isOffline, let feed else { return false }
        sending = action
        message = nil
        canRetryAfterMessage = false
        feedbackError = nil
        let outcome = await feed.perform(action)
        sending = nil
        switch outcome {
        case .done:
            successCount += 1
            didChange = true
            if case .requestChanges = action { feedback = "" }
            return true
        case .refused(let code, let text, let fieldErrors, let connectURL):
            switch code {
            case "already_resolved":
                // The task was reloaded; it shows what was decided, which is not an error.
                return true
            case "not_linked":
                linkOverride = connectURL ?? (task?.coOwner?.connectUrl)
            default:
                message = text
                canRetryAfterMessage = ["github_failed", "access_check_failed"].contains(code)
            }
            if case .requestChanges = action { feedbackError = fieldErrors["feedback"]?.first }
            if code != "not_linked" { errorCount += 1 }
            return false
        case .failed(let failure):
            errorCount += 1
            message = Self.words(for: failure, action: action)
            return false
        }
    }

    private static func words(for failure: ThreadProblem, action: TaskAction?) -> String {
        if case .approve? = action, failure == .offline {
            return String(localized: "task.approve.stillOpening")
        }
        switch failure {
        case .offline: return String(localized: "task.action.offline")
        case .server(_, let text) where !text.isEmpty: return text
        default: return String(localized: "task.action.failed")
        }
    }

    func openThread() {
        guard let id = task?.threadId else { return }
        scene.open(thread: id, isCompactWidth: isCompactWidth)
    }

    // MARK: Early warning

    /// Asks who can approve; asks again, a few times, while the server says the list is not complete.
    func loadCandidates() async {
        guard let client = await session.apiClient() else {
            candidates = .failed
            return
        }
        candidates = .loading
        for round in 0..<Self.candidateRounds {
            do {
                let answer = try await client.send(.coOwnerCandidates(task?.task.id ?? taskID))
                let isChecking = !answer.isComplete && round + 1 < Self.candidateRounds
                candidates = .loaded(answer.candidates, isChecking: isChecking)
                guard isChecking else { return }
                try await session.clock.sleep(for: Self.candidateDelay)
            } catch is CancellationError {
                return
            } catch {
                candidates = .failed
                return
            }
        }
    }
}
