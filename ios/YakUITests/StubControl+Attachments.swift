import Foundation

/// What the stub's attachment feature saw in the last upload.
struct StubUpload: Decodable {
    struct File: Decodable {
        let name: String
        let fileName: String
        let contentType: String
        let byteCount: Int
    }
    let fields: [String: [String]]
    let files: [File]
}

extension StubControl {
    /// The last multipart upload the stub took; nil before one arrived (the stub answers 404 until then).
    func lastUpload() -> StubUpload? {
        guard let text = getIfPresent("attachments") else { return nil }
        return try? JSONDecoder().decode(StubUpload.self, from: Data(text.utf8))
    }
}
