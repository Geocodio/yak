/// The token response of `device/token` and `device/pair`.
public struct PairingResponse: Decodable, Sendable, Equatable {
    public struct User: Decodable, Sendable, Equatable {
        public let id: LenientID
        public let name: String
        public let email: String
    }

    public let token: String
    public let device: Me.DeviceInfo
    public let user: User
}
