public struct ObservationOutcome: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let acted = ObservationOutcome(rawValue: "acted")
    public static let declined = ObservationOutcome(rawValue: "declined")
}

public struct ObservationRow: Codable, Equatable, Sendable, Identifiable {
    public let id: Int
    public let repo: String?
    public let source: String
    public let sourceLabel: String
    public let kind: String
    public let kindLabel: String
    public let outcome: ObservationOutcome
    public let summary: String
    public let subject: String?
    public let referenceUrl: String?
    public let taskId: String?
    public let taskUrl: String?
    public let createdAgo: String
    public let createdAt: String
}

/// The filters of the Observations list. Sent as query items by `Endpoint.observations`.
public struct ObservationQuery: Equatable, Codable, Sendable {
    public var repo: String?
    public var outcome: ObservationOutcome?
    public var search: String?

    public init() {}
}

public struct ObservationOptions: Codable, Equatable, Sendable {
    public let repos: [String]
}

public struct ObservationPage: Codable, Equatable, Sendable {
    public let observations: [ObservationRow]
    public let nextPage: Int?
    public let options: ObservationOptions
}
