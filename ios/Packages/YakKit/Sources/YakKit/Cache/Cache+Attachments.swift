import Foundation

#if canImport(os)
    import os
#endif

private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "Thumbnails").error("\(message, privacy: .public)")
    #endif
}

extension Cache {
    public static let thumbnailsPerThread = 200

    private func thumbnailFolder(_ thread: String) -> String { "\(folder(thread))/attachments" }

    private func thumbnailPath(_ thread: String, _ attachment: String) -> String {
        "\(thumbnailFolder(thread))/\(Endpoint<ThreadShow>.segment(attachment)).jpg"
    }

    /// Nil when missing or while the device is locked.
    public func thumbnail(thread: String, attachment: String) -> Data? {
        do {
            return try files.read(thumbnailPath(thread, attachment))
        } catch {
            logFailure("thumbnail read failed: \(type(of: error))")
            return nil
        }
    }

    /// Replaces an existing thumbnail; a new one past `thumbnailsPerThread` is not stored.
    public func saveThumbnail(_ data: Data, thread: String, attachment: String) {
        let path = thumbnailPath(thread, attachment)
        do {
            let names = try files.list(thumbnailFolder(thread))
            guard names.count < Self.thumbnailsPerThread || names.contains((path as NSString).lastPathComponent)
            else { return }
            try files.write(data, to: path, protection: .complete)
        } catch {
            logFailure("thumbnail write failed: \(type(of: error))")
        }
    }
}
