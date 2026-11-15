import Foundation

/// A `multipart/form-data` body (RFC 7578). The same parts and boundary always encode to the same bytes.
public struct MultipartBody: Sendable, Equatable {
    public enum Part: Sendable, Equatable {
        case field(name: String, value: String)
        case file(name: String, fileName: String, contentType: String, data: Data)
    }

    public let parts: [Part]
    public let boundary: String

    public init(parts: [Part], boundary: String) {
        self.parts = parts
        self.boundary = boundary
    }

    public var contentType: String { "multipart/form-data; boundary=\(boundary)" }

    public func encoded() -> Data {
        var body = Data()
        func append(_ text: String) { body.append(Data(text.utf8)) }
        for part in parts {
            append("--\(boundary)\r\n")
            switch part {
            case .field(let name, let value):
                append("Content-Disposition: form-data; name=\"\(Self.quoted(name))\"\r\n\r\n")
                append(value)
            case .file(let name, let fileName, let contentType, let data):
                append(
                    "Content-Disposition: form-data; name=\"\(Self.quoted(name))\"; filename=\"\(Self.quoted(fileName))\"\r\n"
                )
                append("Content-Type: \(Self.headerValue(contentType))\r\n\r\n")
                body.append(data)
            }
            append("\r\n")
        }
        append("--\(boundary)--\r\n")
        return body
    }

    /// A header parameter value: quotes and line breaks cannot end the parameter early.
    private static func quoted(_ text: String) -> String {
        headerValue(text).replacingOccurrences(of: "\"", with: "%22")
    }

    private static func headerValue(_ text: String) -> String {
        text.replacingOccurrences(of: "\r", with: "").replacingOccurrences(of: "\n", with: "")
    }
}
