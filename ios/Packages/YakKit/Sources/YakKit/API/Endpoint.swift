import Foundation

/// A request description. `Response` is the type its body decodes to.
public struct Endpoint<Response>: Sendable {
    public enum Method: String, Sendable {
        case get = "GET"
        case post = "POST"
        case put = "PUT"
        case patch = "PATCH"
        case delete = "DELETE"
    }

    public let method: Method
    /// Relative to `/api/app/v1/`, without a leading slash.
    public let path: String
    public let query: [URLQueryItem]
    /// Encoded as JSON by `APIClient.send`, where an encoding failure is thrown.
    public let body: (any Encodable & Sendable)?
    /// Sent instead of `body` when there is one.
    public let multipart: MultipartBody?
    /// Extra request headers. `Authorization`, `X-Yak-Protocol`, `User-Agent`, `Accept` and `Content-Type`
    /// are the client's own and are never taken from here.
    public let headers: [String: String]
    /// The request's timeout, kept between 1 and 60 seconds. 15 seconds unless the endpoint can wake a sandbox or is a written exception.
    public let timeout: Duration
    /// True: a GET is retried on 502, 503, 504 and network errors. False: sent once; the caller owns its polling.
    public let retriesTransientFailures: Bool

    public init(
        method: Method, path: String, query: [URLQueryItem] = [], body: (any Encodable & Sendable)? = nil,
        multipart: MultipartBody? = nil, headers: [String: String] = [:], timeout: Duration = .seconds(15),
        retriesTransientFailures: Bool = true
    ) {
        self.method = method
        self.path = path
        self.query = query
        self.body = body
        self.multipart = multipart
        self.headers = headers
        self.timeout = min(max(timeout, .seconds(1)), .seconds(60))
        self.retriesTransientFailures = retriesTransientFailures
    }

    /// One path segment with everything but A-Z a-z 0-9 - . _ ~ percent-encoded, so an id never adds a segment.
    public static func segment(_ value: String) -> String {
        let unreserved = CharacterSet(
            charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")
        return value.addingPercentEncoding(withAllowedCharacters: unreserved) ?? ""
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

private struct DevicePayload: Encodable, Sendable {
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

private struct PairBody: Encodable, Sendable {
    let code: String
    let requestID: String?
    let description: DevicePayload

    enum CodingKeys: String, CodingKey {
        case code
        case requestID = "request_id"
    }

    func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(code, forKey: .code)
        try container.encodeIfPresent(requestID, forKey: .requestID)
        try description.encode(to: encoder)
    }
}

private struct PollBody: Encodable, Sendable {
    let deviceCode: String
    let requestID: String

    enum CodingKeys: String, CodingKey {
        case deviceCode = "device_code"
        case requestID = "request_id"
    }
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
        let description = DevicePayload(name: name, model: model, osVersion: osVersion, appVersion: appVersion)
        return Endpoint(method: .post, path: "device/code", body: description)
    }
}

extension Endpoint where Response == PairingResponse {
    public static func pollDeviceToken(deviceCode: String, requestID: String) -> Endpoint<PairingResponse> {
        Endpoint(
            method: .post, path: "device/token", body: PollBody(deviceCode: deviceCode, requestID: requestID))
    }

    /// `requestID` makes a repeat of the same redeem safe: a server that knows it answers the repeat with
    /// the same token, and one that does not ignores it.
    public static func pair(
        code: String, requestID: String? = nil, name: String, model: String, osVersion: String,
        appVersion: String
    ) -> Endpoint<PairingResponse> {
        let description = DevicePayload(name: name, model: model, osVersion: osVersion, appVersion: appVersion)
        return Endpoint(
            method: .post, path: "device/pair",
            body: PairBody(code: code, requestID: requestID, description: description))
    }
}

extension Endpoint where Response == EmptyResponse {
    public static func unpair() -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "device")
    }
}
