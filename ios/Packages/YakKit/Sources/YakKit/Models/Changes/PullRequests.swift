/// The pull request a thread has opened.
public struct ThreadPullRequestLink: Codable, Equatable, Sendable {
    public let number: Int
    public let url: String

    public init(number: Int, url: String) {
        self.number = number
        self.url = url
    }
}

public struct PullRequestOpeningState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let opening = PullRequestOpeningState(rawValue: "opening")
    public static let held = PullRequestOpeningState(rawValue: "held")
    public static let failed = PullRequestOpeningState(rawValue: "failed")
}

/// An opening that is running, waiting for a co-owner, or has failed. Absent when none is.
public struct PullRequestOpening: Codable, Equatable, Sendable {
    public let state: PullRequestOpeningState
    public let error: String?
    public let at: String?

    public init(state: PullRequestOpeningState, error: String? = nil, at: String? = nil) {
        self.state = state
        self.error = error
        self.at = at
    }
}

public struct PullRequestPanelState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let none = PullRequestPanelState(rawValue: "none")
    public static let waitingForCI = PullRequestPanelState(rawValue: "waiting_for_ci")
    public static let blocked = PullRequestPanelState(rawValue: "blocked")
    public static let ready = PullRequestPanelState(rawValue: "ready")
    public static let partial = PullRequestPanelState(rawValue: "partial")
    public static let done = PullRequestPanelState(rawValue: "done")
}

public struct PullRequestState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let open = PullRequestState(rawValue: "open")
    public static let merged = PullRequestState(rawValue: "merged")
    public static let closed = PullRequestState(rawValue: "closed")
}

public struct CheckState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let passing = CheckState(rawValue: "passing")
    public static let failing = CheckState(rawValue: "failing")
    public static let pending = CheckState(rawValue: "pending")
}

public struct BannerTone: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let info = BannerTone(rawValue: "info")
    public static let danger = BannerTone(rawValue: "danger")
    public static let success = BannerTone(rawValue: "success")
}

/// The answer word of the pull request POST and PUT endpoints.
public struct PullRequestStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let opening = PullRequestStatus(rawValue: "opening")
    public static let open = PullRequestStatus(rawValue: "open")
    public static let held = PullRequestStatus(rawValue: "held")
    public static let updated = PullRequestStatus(rawValue: "updated")
    public static let sent = PullRequestStatus(rawValue: "sent")
}

public struct PullRequestBanner: Codable, Equatable, Sendable {
    public let title: String
    public let body: String
    public let tone: BannerTone

    public init(title: String, body: String, tone: BannerTone) {
        self.title = title
        self.body = body
        self.tone = tone
    }
}

public struct PullRequestCheck: Codable, Equatable, Sendable {
    public let name: String
    public let state: CheckState
    public let durationSeconds: Int?
    public let excerpt: String?

    public init(name: String, state: CheckState, durationSeconds: Int? = nil, excerpt: String? = nil) {
        self.name = name
        self.state = state
        self.durationSeconds = durationSeconds
        self.excerpt = excerpt
    }
}

public struct PullRequestChecks: Codable, Equatable, Sendable {
    public let status: CheckState
    public let passedChecks: Int
    public let totalChecks: Int
    public let checks: [PullRequestCheck]

    public init(status: CheckState, passedChecks: Int, totalChecks: Int, checks: [PullRequestCheck]) {
        self.status = status
        self.passedChecks = passedChecks
        self.totalChecks = totalChecks
        self.checks = checks
    }
}

public struct MergeAfter: Codable, Equatable, Sendable {
    public let repo: String
    public let number: Int?
    public let reason: String?

    public init(repo: String, number: Int? = nil, reason: String? = nil) {
        self.repo = repo
        self.number = number
        self.reason = reason
    }
}

public struct PullRequestRow: Codable, Equatable, Sendable, Identifiable {
    public let rowId: Int
    public let repo: String
    public let repoInitials: String
    public let role: RepositoryRole
    public let number: Int?
    public let url: String
    public let title: String
    public let state: PullRequestState
    public let closedAt: String?
    public let ci: PullRequestChecks?
    public let mergeAfter: MergeAfter?
    public let siblingBlock: String?
    public let canAskToFix: Bool

    public var id: Int { rowId }

    public init(
        rowId: Int, repo: String, repoInitials: String, role: RepositoryRole, number: Int?, url: String,
        title: String, state: PullRequestState, closedAt: String? = nil, ci: PullRequestChecks? = nil,
        mergeAfter: MergeAfter? = nil, siblingBlock: String? = nil, canAskToFix: Bool = false
    ) {
        self.rowId = rowId
        self.repo = repo
        self.repoInitials = repoInitials
        self.role = role
        self.number = number
        self.url = url
        self.title = title
        self.state = state
        self.closedAt = closedAt
        self.ci = ci
        self.mergeAfter = mergeAfter
        self.siblingBlock = siblingBlock
        self.canAskToFix = canAskToFix
    }
}

public struct RepositoryWithoutPullRequest: Codable, Equatable, Sendable {
    public let repo: String
    public let role: RepositoryRole
    public let reason: String
    public let note: String

    public init(repo: String, role: RepositoryRole, reason: String, note: String) {
        self.repo = repo
        self.role = role
        self.reason = reason
        self.note = note
    }
}

public struct PullRequestsPanel: Codable, Equatable, Sendable {
    public let state: PullRequestPanelState
    public let banner: PullRequestBanner?
    public let openCount: Int
    public let totalCount: Int
    public let canDrive: Bool
    public let pullRequests: [PullRequestRow]
    public let withoutPullRequest: [RepositoryWithoutPullRequest]
    public let expectations: [String]

    public init(
        state: PullRequestPanelState, banner: PullRequestBanner? = nil, openCount: Int, totalCount: Int,
        canDrive: Bool, pullRequests: [PullRequestRow], withoutPullRequest: [RepositoryWithoutPullRequest] = [],
        expectations: [String] = []
    ) {
        self.state = state
        self.banner = banner
        self.openCount = openCount
        self.totalCount = totalCount
        self.canDrive = canDrive
        self.pullRequests = pullRequests
        self.withoutPullRequest = withoutPullRequest
        self.expectations = expectations
    }
}
