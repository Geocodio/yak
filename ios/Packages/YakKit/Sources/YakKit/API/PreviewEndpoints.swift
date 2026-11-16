import Foundation

private struct PreviewTicketBody: Encodable, Sendable {
    let path: String
}

extension Endpoint where Response == PreviewStatus {
    /// Never wakes anything. The preview session owns the polling, so a failure is not retried here.
    public static func previewStatus(_ id: String) -> Endpoint<PreviewStatus> {
        Endpoint(method: .get, path: "threads/\(segment(id))/preview/status", retriesTransientFailures: false)
    }
}

extension Endpoint where Response == EmptyResponse {
    /// Answers 204 at once; the wake runs after the response.
    public static func warmPreview(_ id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/preview/warm")
    }

    public static func previewHeartbeat(_ id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/preview/heartbeat")
    }
}

extension Endpoint where Response == PreviewTicket {
    /// Sends `path` as given; `PreviewAddress` normalizes it.
    public static func previewTicket(_ id: String, path: String) -> Endpoint<PreviewTicket> {
        Endpoint(
            method: .post, path: "threads/\(segment(id))/preview/ticket", body: PreviewTicketBody(path: path))
    }
}

extension Endpoint where Response == StatusResponse {
    public static func restorePreview(_ id: String) -> Endpoint<StatusResponse> {
        Endpoint(method: .post, path: "threads/\(segment(id))/preview/restore")
    }
}
