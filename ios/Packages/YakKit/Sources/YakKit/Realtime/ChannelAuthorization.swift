/// The answer of `POST broadcasting/auth`: `channel_data` is present for a presence channel only.
public struct ChannelAuthorization: Decodable, Equatable, Sendable {
    public let auth: String
    public let channelData: String?

    enum CodingKeys: String, CodingKey {
        case auth
        case channelData = "channel_data"
    }
}
