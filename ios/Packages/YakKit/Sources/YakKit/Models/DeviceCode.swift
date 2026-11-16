/// The answer that starts the device sign-in flow.
public struct DeviceCode: Decodable, Sendable, Equatable {
    public let deviceCode: String
    public let userCode: String
    public let verificationUrl: String
    public let verificationUrlComplete: String
    public let expiresIn: Int
    public let interval: Int

    enum CodingKeys: String, CodingKey {
        case deviceCode = "device_code"
        case userCode = "user_code"
        case verificationUrl = "verification_url"
        case verificationUrlComplete = "verification_url_complete"
        case expiresIn = "expires_in"
        case interval
    }
}
