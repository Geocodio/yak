/// The list under `threadFilters`. A list keeps its string elements and drops the others; a value that is not a list
/// reads as absent (`names` is nil), as from an older server, and never fails `me`.
public struct ThreadFilterNames: Decodable, Sendable, Equatable {
    public let names: [String]?

    public init(names: [String]?) { self.names = names }

    public init(from decoder: any Decoder) throws {
        guard let entries = try? [LossyString](from: decoder) else {
            names = nil
            return
        }
        names = entries.compactMap(\.value)
    }

    private struct LossyString: Decodable {
        let value: String?
        init(from decoder: any Decoder) throws { value = try? String(from: decoder) }
    }
}

/// The signed-in answer: who this phone is and what the instance offers it.
public struct Me: Decodable, Sendable, Equatable {
    public struct UserInfo: Decodable, Sendable, Equatable {
        public let id: LenientID
        public let name: String
        public let email: String
        public let initials: String
        public let isAdmin: Bool
    }

    public struct DeviceInfo: Decodable, Sendable, Equatable {
        public let id: LenientID
        public let name: String
        /// Kept as text so a kind added by a newer server still decodes.
        public let kind: String
        /// Sent by `me` only, not by the pairing responses.
        public let pairedAt: String?
    }

    public struct InstanceDescription: Decodable, Sendable, Equatable {
        public let name: String
        public let url: String
        public let version: String?
    }

    public struct Realtime: Decodable, Sendable, Equatable {
        public let host: String
        public let key: String
        public let port: Int
        public let scheme: String
    }

    public struct Terminal: Decodable, Sendable, Equatable {
        public let wsUrl: String
    }

    public struct AttachmentLimits: Decodable, Sendable, Equatable {
        public let maxFiles: Int
        public let maxFileBytes: Int
    }

    public struct PushState: Decodable, Sendable, Equatable {
        public let apns: Bool
        public let bundleId: String?
        public let environment: String?
    }

    /// The web views this instance offers; absent on an older server.
    public struct WebSupport: Decodable, Sendable, Equatable {
        /// The version of the embedded thread page; 1 is the first.
        public let embed: Int

        public init(embed: Int) { self.embed = embed }

        /// A `web` object without a whole-number `embed` offers nothing, and must not fail the whole `me` decode.
        public init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            embed = (try? container.decodeIfPresent(Int.self, forKey: .embed)) ?? 0
        }

        private enum CodingKeys: String, CodingKey { case embed }
    }

    public let user: UserInfo
    public let device: DeviceInfo
    public let instance: InstanceDescription
    public let needsYouCount: Int
    public let realtime: Realtime?
    public let terminal: Terminal
    public let attachments: AttachmentLimits
    public let push: PushState
    public let features: [String: Bool]
    public let web: WebSupport?
    /// The `filter` words `GET threads` accepts; the key is absent on an older server.
    public let threadFilters: ThreadFilterNames?

    /// Nil on an older server, which has no Review or Closed tab and takes `yak` as a filter.
    public var threadFilterWords: [String]? { threadFilters?.names }

    /// True when the instance serves the embedded thread page.
    public var supportsEmbeddedThread: Bool { (web?.embed ?? 0) >= 1 }
}
