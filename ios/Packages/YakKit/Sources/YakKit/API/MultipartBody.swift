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
        for part in parts {
            body.append(head(of: part))
            switch part {
            case .field(_, let value): body.append(Data(value.utf8))
            case .file(_, _, _, let data): body.append(data)
            }
            body.append(Self.partEnd)
        }
        body.append(closing)
        return body
    }

    /// The boundary line and headers of a part, up to the first byte of its content.
    func head(of part: Part) -> Data {
        var text = "--\(boundary)\r\n"
        switch part {
        case .field(let name, _):
            text += "Content-Disposition: form-data; name=\"\(Self.quoted(name))\"\r\n\r\n"
        case .file(let name, let fileName, let contentType, _):
            text +=
                "Content-Disposition: form-data; name=\"\(Self.quoted(name))\"; filename=\"\(Self.quoted(fileName))\"\r\n"
            text += "Content-Type: \(Self.headerValue(contentType))\r\n\r\n"
        }
        return Data(text.utf8)
    }

    static let partEnd = Data("\r\n".utf8)

    var closing: Data { Data("--\(boundary)--\r\n".utf8) }

    /// A header parameter value: quotes and line breaks cannot end the parameter early.
    private static func quoted(_ text: String) -> String {
        headerValue(text).replacingOccurrences(of: "\"", with: "%22")
    }

    private static func headerValue(_ text: String) -> String {
        text.replacingOccurrences(of: "\r", with: "").replacingOccurrences(of: "\n", with: "")
    }
}
