import Foundation

private struct EmptyObject: Encodable, Sendable {}

private struct SaveFileBody: Encodable, Sendable {
    let scope: FileScope
    let path: String
    let content: String
    let baseSHA256: String
    let overwrite: Bool

    enum CodingKeys: String, CodingKey {
        case scope, path, content, overwrite
        case baseSHA256 = "base_sha256"
    }
}

extension Endpoint where Response == TerminalSessionAnswer {
    /// POST threads/{id}/terminal-sessions, body {}, header Origin, 60 seconds.
    public static func openTerminalSession(_ id: String, origin: String) -> Endpoint<TerminalSessionAnswer> {
        Endpoint(
            method: .post, path: "threads/\(segment(id))/terminal-sessions", body: EmptyObject(),
            headers: ["Origin": origin], timeout: .seconds(60))
    }
}

extension Endpoint where Response == FileTreeFolder {
    /// GET threads/{id}/files?scope=&path= (path left out when empty), 60 seconds, sent once.
    public static func fileTree(_ id: String, scope: FileScope, path: String) -> Endpoint<FileTreeFolder> {
        var query = [URLQueryItem(name: "scope", value: scope.rawValue)]
        if !path.isEmpty { query.append(URLQueryItem(name: "path", value: path)) }
        return Endpoint(
            method: .get, path: "threads/\(segment(id))/files", query: query, timeout: .seconds(60),
            retriesTransientFailures: false)
    }
}

extension Endpoint where Response == FileContent {
    /// GET threads/{id}/files/content?scope=&path=, 60 seconds, sent once.
    public static func fileContent(_ id: String, scope: FileScope, path: String) -> Endpoint<FileContent> {
        Endpoint(
            method: .get, path: "threads/\(segment(id))/files/content",
            query: [URLQueryItem(name: "scope", value: scope.rawValue), URLQueryItem(name: "path", value: path)],
            timeout: .seconds(60), retriesTransientFailures: false)
    }
}

extension Endpoint where Response == FileSaveResult {
    /// PUT threads/{id}/files/content, body {scope, path, content, base_sha256, overwrite}, 60 seconds.
    public static func saveFile(
        _ id: String, scope: FileScope, path: String, content: String, baseSHA256: String, overwrite: Bool
    ) -> Endpoint<FileSaveResult> {
        Endpoint(
            method: .put, path: "threads/\(segment(id))/files/content",
            body: SaveFileBody(
                scope: scope, path: path, content: content, baseSHA256: baseSHA256, overwrite: overwrite),
            timeout: .seconds(60))
    }
}

extension Endpoint where Response == FileSearchResult {
    /// GET threads/{id}/files/search?scope=repo&q=, 15 seconds, sent once: a failed search falls back to the
    /// local filter instead of asking again.
    public static func searchFiles(_ id: String, query: String) -> Endpoint<FileSearchResult> {
        Endpoint(
            method: .get, path: "threads/\(segment(id))/files/search",
            query: [
                URLQueryItem(name: "scope", value: FileScope.repo.rawValue), URLQueryItem(name: "q", value: query),
            ],
            retriesTransientFailures: false)
    }
}
