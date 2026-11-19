import Foundation

/// Request bodies shared by the task and thread action routes.
struct RepositoryBody: Encodable, Sendable { let repo: String }
struct FeedbackBody: Encodable, Sendable { let feedback: String }
struct TagBody: Encodable, Sendable {
    let userID: Int
    enum CodingKeys: String, CodingKey { case userID = "user_id" }
}

/// A trimmed value cut to `limit` characters, or nil when nothing is left.
func cleaned(_ value: String?, limit: Int) -> String? {
    guard let trimmed = value?.trimmingCharacters(in: .whitespacesAndNewlines), !trimmed.isEmpty else { return nil }
    return String(trimmed.prefix(limit))
}
