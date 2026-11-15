public struct SandboxState: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let none = SandboxState(rawValue: "none")
    public static let starting = SandboxState(rawValue: "starting")
    public static let running = SandboxState(rawValue: "running")
    public static let paused = SandboxState(rawValue: "paused")
    public static let stopped = SandboxState(rawValue: "stopped")
    public static let destroying = SandboxState(rawValue: "destroying")
    public static let destroyed = SandboxState(rawValue: "destroyed")
}

public struct RepositoryRole: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let primary = RepositoryRole(rawValue: "primary")
    public static let changes = RepositoryRole(rawValue: "changes")
    public static let context = RepositoryRole(rawValue: "context")
}

public struct RepositorySetupStatus: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let pending = RepositorySetupStatus(rawValue: "pending")
    public static let cloning = RepositorySetupStatus(rawValue: "cloning")
    public static let setup = RepositorySetupStatus(rawValue: "setup")
    public static let ready = RepositorySetupStatus(rawValue: "ready")
    public static let failed = RepositorySetupStatus(rawValue: "failed")

    /// True while the repository is still being prepared.
    public var isInProgress: Bool { self == .pending || self == .cloning || self == .setup }
}

public struct ThreadEnvironment: Codable, Equatable, Sendable {
    public struct Sandbox: Codable, Equatable, Sendable {
        public let state: SandboxState
        public let stateChangedAt: String?

        public init(state: SandboxState, stateChangedAt: String? = nil) {
            self.state = state
            self.stateChangedAt = stateChangedAt
        }
    }

    public struct PullRequest: Codable, Equatable, Sendable {
        public let number: Int
        public let url: String
        public let state: String

        public init(number: Int, url: String, state: String) {
            self.number = number
            self.url = url
            self.state = state
        }
    }

    public struct Deployment: Codable, Equatable, Sendable {
        public let url: String
        public let status: String

        public init(url: String, status: String) {
            self.url = url
            self.status = status
        }
    }

    public struct Account: Codable, Equatable, Sendable {
        public let name: String
        public let kind: String
        public let isYours: Bool

        public init(name: String, kind: String, isYours: Bool) {
            self.name = name
            self.kind = kind
            self.isYours = isYours
        }
    }

    public struct StartedBy: Codable, Equatable, Sendable {
        public let name: String
        public let at: String

        public init(name: String, at: String) {
            self.name = name
            self.at = at
        }
    }

    public let sandbox: Sandbox
    public let branch: String?
    public let pullRequest: PullRequest?
    public let deployment: Deployment?
    public let account: Account?
    public let model: String?
    public let startedBy: StartedBy?
    public let stoppedAfterMinutes: Int
    public let repositories: [EnvironmentRepository]
    public let notUploadedDirectories: [String]

    public init(
        sandbox: Sandbox,
        branch: String? = nil,
        pullRequest: PullRequest? = nil,
        deployment: Deployment? = nil,
        account: Account? = nil,
        model: String? = nil,
        startedBy: StartedBy? = nil,
        stoppedAfterMinutes: Int,
        repositories: [EnvironmentRepository],
        notUploadedDirectories: [String] = []
    ) {
        self.sandbox = sandbox
        self.branch = branch
        self.pullRequest = pullRequest
        self.deployment = deployment
        self.account = account
        self.model = model
        self.startedBy = startedBy
        self.stoppedAfterMinutes = stoppedAfterMinutes
        self.repositories = repositories
        self.notUploadedDirectories = notUploadedDirectories
    }
}

public struct EnvironmentRepository: Codable, Equatable, Sendable, Identifiable {
    public let id: LenientID
    public let repo: String
    public let role: RepositoryRole
    public let path: String
    public let setupStatus: RepositorySetupStatus
    public let setupError: String?
    public let setupOutput: String?
    public let addedBy: String?
    public let addedAt: String?
    public let isDatasetAttached: Bool
    public let canMakeContextOnly: Bool

    public init(
        id: LenientID,
        repo: String,
        role: RepositoryRole,
        path: String,
        setupStatus: RepositorySetupStatus,
        setupError: String? = nil,
        setupOutput: String? = nil,
        addedBy: String? = nil,
        addedAt: String? = nil,
        isDatasetAttached: Bool = false,
        canMakeContextOnly: Bool = false
    ) {
        self.id = id
        self.repo = repo
        self.role = role
        self.path = path
        self.setupStatus = setupStatus
        self.setupError = setupError
        self.setupOutput = setupOutput
        self.addedBy = addedBy
        self.addedAt = addedAt
        self.isDatasetAttached = isDatasetAttached
        self.canMakeContextOnly = canMakeContextOnly
    }
}
