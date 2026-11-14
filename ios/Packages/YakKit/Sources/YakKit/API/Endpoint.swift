import Foundation

/// A request description. `Response` is the type its body decodes to.
public struct Endpoint<Response>: Sendable {
    public enum Method: String, Sendable {
        case get = "GET"
        case post = "POST"
        case delete = "DELETE"
    }

    public let method: Method
    /// Relative to `/api/app/v1/`, without a leading slash.
    public let path: String
    public let body: Data?

    public init(method: Method, path: String, body: Data? = nil) {
        self.method = method
        self.path = path
        self.body = body
    }
}

extension Endpoint where Response == InstanceInfo {
    public static func instance() -> Endpoint<InstanceInfo> {
        Endpoint(method: .get, path: "instance")
    }
}

/// The body of an answer that has none, such as a 204.
public struct EmptyResponse: Decodable, Sendable, Equatable {
    public init() {}
}

private struct DeviceDescription: Encodable {
    let name: String
    let model: String
    let osVersion: String
    let appVersion: String

    enum CodingKeys: String, CodingKey {
        case name, model
        case osVersion = "os_version"
        case appVersion = "app_version"
    }
}

private struct PairBody: Encodable {
    let code: String
    let description: DeviceDescription

    enum CodingKeys: String, CodingKey { case code }

    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(code, forKey: .code)
        try description.encode(to: encoder)
    }
}

private struct PollBody: Encodable {
    let deviceCode: String

    enum CodingKeys: String, CodingKey { case deviceCode = "device_code" }
}

extension Endpoint where Response == Me {
    public static func me() -> Endpoint<Me> {
        Endpoint(method: .get, path: "me")
    }
}

extension Endpoint where Response == DeviceCode {
    public static func startDeviceFlow(name: String, model: String, osVersion: String, appVersion: String)
        -> Endpoint<DeviceCode>
    {
        let description = DeviceDescription(name: name, model: model, osVersion: osVersion, appVersion: appVersion)
        return Endpoint(method: .post, path: "device/code", body: try? JSONEncoder().encode(description))
    }
}

extension Endpoint where Response == PairingResponse {
    public static func pollDeviceToken(deviceCode: String) -> Endpoint<PairingResponse> {
        Endpoint(method: .post, path: "device/token", body: try? JSONEncoder().encode(PollBody(deviceCode: deviceCode)))
    }

    public static func pair(code: String, name: String, model: String, osVersion: String, appVersion: String)
        -> Endpoint<PairingResponse>
    {
        let description = DeviceDescription(name: name, model: model, osVersion: osVersion, appVersion: appVersion)
        return Endpoint(
            method: .post, path: "device/pair",
            body: try? JSONEncoder().encode(PairBody(code: code, description: description)))
    }
}

extension Endpoint where Response == EmptyResponse {
    public static func unpair() -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "device")
    }
}
