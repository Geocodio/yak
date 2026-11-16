import Foundation

private struct AddSubThreadBody: Encodable, Sendable {
    let name: String
    let brief: String
    let kind: String
    let model: String?
    let sendKey: String

    enum CodingKeys: String, CodingKey {
        case name, brief, kind, model
        case sendKey = "send_key"
    }

    /// `model` is always present, `null` when the server should choose.
    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(name, forKey: .name)
        try container.encode(brief, forKey: .brief)
        try container.encode(kind, forKey: .kind)
        try container.encode(model, forKey: .model)
        try container.encode(sendKey, forKey: .sendKey)
    }
}

private struct AwakeLimitBody: Encodable, Sendable {
    let limit: Int
}

private struct ShareBody: Encodable, Sendable {
    let mode: String
    let userIds: [Int]

    enum CodingKeys: String, CodingKey {
        case mode
        case userIds = "user_ids"
    }
}

extension Endpoint where Response == ThreadStack {
    public static func stack(_ id: String) -> Endpoint<ThreadStack> {
        Endpoint(method: .get, path: "threads/\(segment(id))/stack")
    }
}

extension Endpoint where Response == SubThreadCreated {
    public static func addSubThread(
        _ parentID: String, name: String, brief: String, kind: StackChildKind, model: String?, sendKey: UUID
    ) -> Endpoint<SubThreadCreated> {
        Endpoint(
            method: .post, path: "threads/\(segment(parentID))/stack/children",
            body: AddSubThreadBody(
                name: name, brief: brief, kind: kind.rawValue, model: model, sendKey: sendKey.uuidString))
    }
}

extension Endpoint where Response == AwakeLimitUpdated {
    public static func setAwakeLimit(_ id: String, limit: Int) -> Endpoint<AwakeLimitUpdated> {
        Endpoint(method: .patch, path: "threads/\(segment(id))/stack/limit", body: AwakeLimitBody(limit: limit))
    }
}

extension Endpoint where Response == StatusResponse {
    public static func stopStack(_ id: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/stack/stop")
    }

    public static func takeOver(_ childID: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(childID))/stack/take-over")
    }

    public static func splitSubThread(_ childID: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(childID))/stack/split")
    }

    public static func dropSubThread(_ childID: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(childID))/stack/drop")
    }

    public static func retryRepositorySetup(_ id: String, repository: LenientID) -> Endpoint<StatusResponse> {
        Endpoint(
            method: .post, path: "threads/\(segment(id))/repositories/\(segment(repository.value))/retry")
    }
}

extension Endpoint where Response == ThreadEnvironment {
    public static func environment(_ id: String) -> Endpoint<ThreadEnvironment> {
        Endpoint(method: .get, path: "threads/\(segment(id))/environment")
    }
}

extension Endpoint where Response == ThreadShare {
    public static func share(_ id: String) -> Endpoint<ThreadShare> {
        Endpoint(method: .get, path: "threads/\(segment(id))/share")
    }
}

extension Endpoint where Response == ThreadShareUpdated {
    /// `user_ids` is always present, empty unless the mode is `people`.
    public static func updateShare(_ id: String, mode: ShareMode, userIDs: [Int]) -> Endpoint<ThreadShareUpdated> {
        Endpoint(
            method: .put, path: "threads/\(segment(id))/share",
            body: ShareBody(mode: mode.rawValue, userIds: mode == .people ? userIDs : []))
    }
}
