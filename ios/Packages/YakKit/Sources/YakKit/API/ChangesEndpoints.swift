import Foundation

private struct OpenPullRequestBody: Encodable, Sendable {
    let acknowledgedPrivatePublish: Bool

    enum CodingKeys: String, CodingKey { case acknowledgedPrivatePublish = "acknowledged_private_publish" }

    /// `{}` when not acknowledged, never `false`.
    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        if acknowledgedPrivatePublish { try container.encode(true, forKey: .acknowledgedPrivatePublish) }
    }
}

private struct ReorderBody: Encodable, Sendable {
    let order: [Int]
}

extension Endpoint where Response == ThreadDiffResponse {
    public static func threadDiff(_ id: String) -> Endpoint<ThreadDiffResponse> {
        Endpoint(method: .get, path: "threads/\(segment(id))/diff")
    }
}

extension Endpoint where Response == DiffFileContent {
    public static func diffFile(_ id: String, fileID: Int) -> Endpoint<DiffFileContent> {
        Endpoint(method: .get, path: "threads/\(segment(id))/diff/files/\(fileID)")
    }
}

extension Endpoint where Response == PullRequestsPanel {
    public static func pullRequests(_ id: String) -> Endpoint<PullRequestsPanel> {
        Endpoint(method: .get, path: "threads/\(segment(id))/pull-requests")
    }
}

extension Endpoint where Response == StatusResponse {
    public static func openPullRequest(_ id: String, acknowledgedPrivatePublish: Bool) -> Endpoint<StatusResponse> {
        Endpoint(
            method: .post, path: "threads/\(segment(id))/pull-request",
            body: OpenPullRequestBody(acknowledgedPrivatePublish: acknowledgedPrivatePublish))
    }

    public static func reorderPullRequests(_ id: String, order: [Int]) -> Endpoint<StatusResponse> {
        Endpoint(
            method: .put, path: "threads/\(segment(id))/pull-requests/order", body: ReorderBody(order: order))
    }

    public static func askToFix(_ id: String, rowID: Int) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/pull-requests/\(rowID)/fix")
    }
}
