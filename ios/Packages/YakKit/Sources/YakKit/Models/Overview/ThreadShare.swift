public struct ShareMode: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let onlyMe = ShareMode(rawValue: "only_me")
    public static let people = ShareMode(rawValue: "people")
    public static let everyone = ShareMode(rawValue: "everyone")
}

public struct SharePerson: Codable, Equatable, Sendable, Identifiable {
    public let id: LenientID
    public let name: String
    public let role: String?

    public init(id: LenientID, name: String, role: String? = nil) {
        self.id = id
        self.name = name
        self.role = role
    }
}

public struct ThreadShare: Codable, Equatable, Sendable {
    public let mode: ShareMode
    public let userIds: [LenientID]
    public let people: [SharePerson]
    public let secretFiles: [String]
    public let memoryFolder: String?
    public let memoryFiles: [String]

    public init(
        mode: ShareMode,
        userIds: [LenientID],
        people: [SharePerson],
        secretFiles: [String] = [],
        memoryFolder: String? = nil,
        memoryFiles: [String] = []
    ) {
        self.mode = mode
        self.userIds = userIds
        self.people = people
        self.secretFiles = secretFiles
        self.memoryFolder = memoryFolder
        self.memoryFiles = memoryFiles
    }
}

public struct ThreadShareUpdated: Codable, Equatable, Sendable {
    public let mode: ShareMode
    public let userIds: [LenientID]

    public init(mode: ShareMode, userIds: [LenientID]) {
        self.mode = mode
        self.userIds = userIds
    }
}
