/// A file a server message row carries. `url` and `downloadUrl` are signed links that expire after 30 minutes.
public struct MessageAttachment: Codable, Equatable, Sendable {
    public let id: LenientID
    public let name: String
    public let reference: String?
    public let url: String
    public let downloadUrl: String?
    public let mimeType: String
    public let size: Int
    public let isImage: Bool
    public let previewKind: String?

    public init(
        id: LenientID, name: String, reference: String? = nil, url: String, downloadUrl: String? = nil,
        mimeType: String, size: Int, isImage: Bool, previewKind: String? = nil
    ) {
        self.id = id
        self.name = name
        self.reference = reference
        self.url = url
        self.downloadUrl = downloadUrl
        self.mimeType = mimeType
        self.size = size
        self.isImage = isImage
        self.previewKind = previewKind
    }
}

extension ThreadMessage {
    /// `attachments ?? []`.
    public var attachmentList: [MessageAttachment] { attachments ?? [] }
}
