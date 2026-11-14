/// The public `instance` answer: what a Yak address says about itself.
public struct InstanceInfo: Decodable, Sendable, Equatable {
    public struct APIRange: Decodable, Sendable, Equatable {
        public let current: Int
        public let min: Int
    }

    public struct PairingWays: Decodable, Sendable, Equatable {
        public let qr: Bool
        public let address: Bool
    }

    public let yak: Bool
    public let name: String
    public let api: APIRange
    public let pairing: PairingWays
}
