import Foundation

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Arbitrary JSON, used for the free-form `details` of an error body.
public enum JSONValue: Codable, Sendable, Equatable {
    case null
    case bool(Bool)
    case number(Double)
    case string(String)
    case array([JSONValue])
    case object([String: JSONValue])

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        // Decode probes: JSONValue tries each shape in turn.
        if container.decodeNil() {
            self = .null
        } else if let value = try? container.decode(Bool.self) {
            self = .bool(value)
        } else if let value = try? container.decode(Double.self) {
            self = .number(value)
        } else if let value = try? container.decode(String.self) {
            self = .string(value)
        } else if let value = try? container.decode([JSONValue].self) {
            self = .array(value)
        } else {
            self = .object(try container.decode([String: JSONValue].self))
        }
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .null: try container.encodeNil()
        case .bool(let value): try container.encode(value)
        case .number(let value): try container.encode(value)
        case .string(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .object(let value): try container.encode(value)
        }
    }
}

extension JSONValue {
    /// The member named `key` when this value is an object that has one.
    public subscript(key: String) -> JSONValue? {
        if case .object(let members) = self { return members[key] }
        return nil
    }

    /// The text when this value is a string.
    public var stringValue: String? {
        if case .string(let text) = self { return text }
        return nil
    }
}

/// The server's error body: `{"error": code, "message": text, "details": {...}}`.
public struct APIError: Error, Equatable, Sendable {
    public let status: Int
    /// "unknown" when the body is not the error shape.
    public let code: String
    public let message: String
    public let details: [String: JSONValue]
    /// Whole seconds from the `Retry-After` header of a 429; nil when it is missing or not a sane number.
    public let retryAfterSeconds: Int?

    public init(
        status: Int, code: String, message: String, details: [String: JSONValue], retryAfterSeconds: Int? = nil
    ) {
        self.status = status
        self.code = code
        self.message = message
        self.details = details
        self.retryAfterSeconds = retryAfterSeconds
    }

    /// Reads the error shape from a response body, or returns nil when the body is not that shape.
    static func parse(status: Int, body: Data) -> APIError? {
        struct Body: Decodable {
            let error: String
            let message: String?
            let details: [String: JSONValue]?
        }
        // Decode probe: a body that is not the error envelope gives nil, and the caller falls back to the status.
        guard let decoded = try? JSONDecoder().decode(Body.self, from: body) else { return nil }
        return APIError(
            status: status, code: decoded.error, message: decoded.message ?? "",
            details: decoded.details ?? [:])
    }
}

/// Every way a request can fail, each mapped to one screen state.
public enum APIFailure: Error, Equatable, Sendable {
    case server(APIError)
    /// 426 protocol_too_old
    case appTooOld(minimum: Int)
    /// The response header is below `ClientProtocol.minimumServer`.
    case serverTooOld(current: Int)
    /// 401: unauthenticated, invalid_token, device_revoked
    case unauthorized(code: String)
    case network(URLError.Code)
    /// A body that is not JSON in the expected shape.
    case notYak
    /// The request body could not be encoded; nothing was sent.
    case encodingFailed
}
