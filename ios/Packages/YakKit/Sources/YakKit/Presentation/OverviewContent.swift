import Foundation

public struct OverviewInputs: Equatable, Sendable {
    public var show: ThreadShow?
    /// `snapshot.thread`: newer than `show` when set.
    public var liveThread: ThreadStateChange?
    /// `snapshot.turn`: the current turn and its state.
    public var liveTurn: TurnChange?
    public var stack: ThreadStack?
    public var environment: ThreadEnvironment?
    /// `me.user.name`.
    public var viewerName: String?
    /// Nil until the preview plan passes `thread.preview.host`.
    public var previewHost: String?

    public init(
        show: ThreadShow?, liveThread: ThreadStateChange? = nil, liveTurn: TurnChange? = nil,
        stack: ThreadStack? = nil, environment: ThreadEnvironment? = nil, viewerName: String? = nil,
        previewHost: String? = nil
    ) {
        self.show = show
        self.liveThread = liveThread
        self.liveTurn = liveTurn
        self.stack = stack
        self.environment = environment
        self.viewerName = viewerName
        self.previewHost = previewHost
    }
}

public enum OverviewGroupKind: String, Equatable, Sendable {
    case request, progress, subThreads, status, links, access, people
}

public enum AddSubThreadAvailability: Equatable, Sendable {
    case allowed
    case refused(code: String, message: String)
}

public struct AwakeStepperValue: Equatable, Sendable {
    public let value: Int
    public let range: ClosedRange<Int>
    public let awake: Int
    public let waiting: Int

    public init(value: Int, range: ClosedRange<Int>, awake: Int, waiting: Int) {
        self.value = value
        self.range = range
        self.awake = awake
        self.waiting = waiting
    }

    public func clamped(_ proposed: Int) -> Int {
        min(max(proposed, range.lowerBound), range.upperBound)
    }
}

public struct SubThreadsGroup: Equatable, Sendable {
    public let total: Int
    /// The most sub-threads one thread can have.
    public let maxChildren: Int
    /// Set when the thread is itself a sub-thread.
    public let parent: StackParent?
    public let rows: [SubThreadRow]
    public let add: AddSubThreadAvailability
    /// The limit line "N can run at the same time": set when the thread is a root with a sub-thread that has its own
    /// sandbox. `canChange` is whether the person may open the stepper behind "Change".
    public let limitLine: LimitLine?
    /// The stepper behind "Change"; nil when the person cannot change the limit.
    public let awake: AwakeStepperValue?
    public let canStop: Bool
    public let stoppedAt: Date?

    public struct LimitLine: Equatable, Sendable {
        public let current: Int
        public let canChange: Bool
    }
}

public enum StatusTone: Equatable, Sendable { case good, attention, neutral }

public struct StatusGroup: Equatable, Sendable {
    public let sandbox: SandboxState
    public let tone: StatusTone
    public let slots: CapacityCounter?
    public let sleepsAfterMinutes: Int?
}

public struct RepositoryRow: Equatable, Sendable, Identifiable {
    public let id: LenientID
    public let repo: String
    public let role: RepositoryRole
    public let status: RepositorySetupStatus
    public let error: String?
    public let output: String?
    public let canRetry: Bool
}

public struct LinksGroup: Equatable, Sendable {
    public let previewHost: String?
    public let branch: String?
    public let pullRequest: ThreadEnvironment.PullRequest?
    public let deployment: ThreadEnvironment.Deployment?
    public let repositories: [RepositoryRow]

    /// Nothing but the primary repository.
    public var isEmpty: Bool {
        previewHost == nil && branch == nil && pullRequest == nil && deployment == nil
            && repositories.allSatisfy { $0.role == .primary }
    }
}

public enum RunsOn: Equatable, Sendable { case yakSandbox, computer }

public struct AccessGroup: Equatable, Sendable {
    public let sshCommand: String
    public let runsOn: RunsOn
}

public enum VisibilityLine: Equatable, Sendable {
    case privateToYou
    case sharedWithPeople(Int)
    case sharedWithTeam, sharedWithYou
}

public enum StartedByLine: Equatable, Sendable {
    case you(at: Date?)
    case person(name: String, at: Date?)
}

public struct PeopleGroup: Equatable, Sendable {
    public let visibility: VisibilityLine
    public let canChangeSharing: Bool
    public let startedBy: StartedByLine?
    public let account: ThreadEnvironment.Account?
}

public enum OverviewActivity: Equatable, Sendable { case working, waiting, idle, blocked, closed }

public struct OverviewContent: Equatable, Sendable {
    public let title: String
    /// The side panel's status line and the title's dot.
    public let activity: OverviewActivity
    public let subThreads: SubThreadsGroup?
    public let status: StatusGroup
    public let links: LinksGroup
    public let access: AccessGroup
    public let people: PeopleGroup

    public var groupOrder: [OverviewGroupKind] {
        (subThreads == nil ? [] : [.subThreads]) + [.status, .links, .access, .people]
    }

    /// Nil while the thread's show has not loaded.
    public static func make(_ inputs: OverviewInputs) -> OverviewContent? {
        guard let show = inputs.show else { return nil }
        let thread = show.thread
        let status = inputs.liveThread?.status ?? thread.status
        return OverviewContent(
            title: thread.title,
            activity: activity(inputs, show: show, status: status),
            subThreads: inputs.stack.flatMap(subThreadsGroup),
            status: statusGroup(inputs, show: show),
            links: linksGroup(inputs, show: show, status: status),
            access: AccessGroup(
                sshCommand: "yak ssh " + thread.id,
                runsOn: thread.ownerSide == "device" || thread.startedOnDevice == true ? .computer : .yakSandbox),
            people: peopleGroup(inputs, show: show))
    }

    private static func subThreadsGroup(_ stack: ThreadStack) -> SubThreadsGroup? {
        guard !stack.children.isEmpty || !stack.isRoot else { return nil }
        let hasOwnSandbox = stack.isRoot && stack.children.contains { $0.kind != .research }
        let awake: AwakeStepperValue? =
            hasOwnSandbox && stack.canDrive
            ? {
                let range = 1...max(1, stack.limit.max)
                return AwakeStepperValue(
                    value: min(max(stack.limit.current, range.lowerBound), range.upperBound), range: range,
                    awake: stack.limit.awake, waiting: stack.limit.waiting)
            }() : nil
        return SubThreadsGroup(
            total: stack.children.count,
            maxChildren: stack.maxChildren,
            parent: stack.parent,
            rows: SubThreadRows.make(stack),
            add: stack.addChild.allowed
                ? .allowed
                : .refused(
                    code: stack.addChild.error ?? "forbidden",
                    message: stack.addChild.message ?? "You cannot add a sub-thread right now."),
            limitLine: hasOwnSandbox
                ? SubThreadsGroup.LimitLine(current: stack.limit.current, canChange: stack.canDrive) : nil,
            awake: awake,
            canStop: stack.canStop,
            stoppedAt: stack.stoppedAt.flatMap(parseDate))
    }

    private static func statusGroup(_ inputs: OverviewInputs, show: ThreadShow) -> StatusGroup {
        let sandbox =
            inputs.liveThread.map { SandboxState(rawValue: $0.sandboxState) }
            ?? inputs.environment?.sandbox.state ?? SandboxState(rawValue: show.thread.sandboxState)
        let tone: StatusTone =
            sandbox == .running ? .good : (sandbox == .starting || sandbox == .paused ? .attention : .neutral)
        return StatusGroup(
            sandbox: sandbox, tone: tone, slots: show.capacity?.share,
            sleepsAfterMinutes: sandbox == .running ? inputs.environment?.stoppedAfterMinutes : nil)
    }

    private static func linksGroup(_ inputs: OverviewInputs, show: ThreadShow, status: ThreadStatus)
        -> LinksGroup
    {
        let environment = inputs.environment
        let isOpenAndDrivable = show.canDrive && status == .open
        return LinksGroup(
            previewHost: inputs.previewHost,
            branch: environment?.branch ?? show.thread.branch,
            pullRequest: environment?.pullRequest,
            deployment: environment?.deployment,
            repositories: (environment?.repositories ?? []).map { repository in
                RepositoryRow(
                    id: repository.id, repo: repository.repo, role: repository.role,
                    status: repository.setupStatus, error: repository.setupError,
                    output: repository.setupOutput,
                    canRetry: isOpenAndDrivable && repository.role != .primary
                        && repository.setupStatus == .failed)
            })
    }

    private static func peopleGroup(_ inputs: OverviewInputs, show: ThreadShow) -> PeopleGroup {
        let thread = show.thread
        let visibility: VisibilityLine
        if thread.visibility == "team" {
            visibility = .sharedWithTeam
        } else if thread.isOwner {
            let count = thread.sharedWithCount ?? 0
            visibility = count > 0 ? .sharedWithPeople(count) : .privateToYou
        } else {
            visibility = .sharedWithYou
        }
        let startedBy = inputs.environment?.startedBy.map { starter -> StartedByLine in
            let at = parseDate(starter.at)
            return thread.isOwner && starter.name == inputs.viewerName
                ? .you(at: at) : .person(name: starter.name, at: at)
        }
        return PeopleGroup(
            visibility: visibility, canChangeSharing: thread.isOwner, startedBy: startedBy,
            account: inputs.environment?.account)
    }

    private static func activity(_ inputs: OverviewInputs, show: ThreadShow, status: ThreadStatus)
        -> OverviewActivity
    {
        if status == .closed { return .closed }
        let blockedReason: String? =
            inputs.liveThread != nil ? inputs.liveThread?.blockedReason : show.thread.blockedReason
        let hasBlockedReason =
            blockedReason.map { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } ?? false
        if show.composer.state == .disabledBlocked || hasBlockedReason { return .blocked }
        let runnerState = inputs.liveThread?.runnerState ?? show.thread.runnerState
        let turnState = inputs.liveTurn?.state
        if turnState == .running || show.composer.state == .working || runnerState == "busy" { return .working }
        if turnState == .waitingForSandbox || turnState == .waitingForAccount
            || show.notice?.kind.hasPrefix("waiting_for_") == true
        {
            return .waiting
        }
        return .idle
    }

    private static func parseDate(_ text: String) -> Date? {
        let withFraction = ISO8601DateFormatter()
        withFraction.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let plain = ISO8601DateFormatter()
        plain.formatOptions = [.withInternetDateTime]
        return withFraction.date(from: text) ?? plain.date(from: text)
    }
}
