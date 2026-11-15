/// The totals of a thread's diff, counted over the repositories that get a pull request plus scratch files.
public struct DiffTotals: Codable, Equatable, Sendable {
    public let additions: Int
    public let deletions: Int
    public let filesCount: Int

    public init(additions: Int, deletions: Int, filesCount: Int = 0) {
        self.additions = additions
        self.deletions = deletions
        self.filesCount = filesCount
    }

    private enum CodingKeys: String, CodingKey { case additions, deletions, filesCount }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        additions = try container.decode(Int.self, forKey: .additions)
        deletions = try container.decode(Int.self, forKey: .deletions)
        filesCount = try container.decodeIfPresent(Int.self, forKey: .filesCount) ?? 0
    }
}
