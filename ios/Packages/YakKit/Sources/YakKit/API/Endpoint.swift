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
