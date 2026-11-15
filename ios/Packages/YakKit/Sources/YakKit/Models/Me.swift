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

    public let user: UserInfo
    public let device: DeviceInfo
    public let instance: InstanceDescription
    public let needsYouCount: Int
    public let realtime: Realtime?
    public let terminal: Terminal
    public let attachments: AttachmentLimits
    public let push: PushState
    public let features: [String: Bool]
}
