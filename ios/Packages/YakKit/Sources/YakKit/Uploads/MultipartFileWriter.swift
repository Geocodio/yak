import Foundation

public enum MultipartFileProblem: Error, Equatable, Sendable {
    case partCountMismatch(expected: Int, given: Int)
    case attachmentMissing(fileName: String)
    /// A file exists but cannot be read now (a locked device).
    case locked
    case notWritten
}

/// Writes a multipart body to a file. Each `.file` part of `body` is written with the bytes of the file at the
/// matching index of `contents` (its own `data` is ignored), read one at a time.
public enum MultipartFileWriter {
    /// Returns the number of bytes written. On any problem the half-written file is removed.
    public static func write(
        _ body: MultipartBody, contents: [String], from files: any FileStore, to path: String,
        protection: FileProtection
    ) throws(MultipartFileProblem) -> Int {
        let expected = body.parts.reduce(0) { count, part in
            if case .file = part { return count + 1 }
            return count
        }
        guard expected == contents.count else {
            throw .partCountMismatch(expected: expected, given: contents.count)
        }
        do {
            try files.remove(path)
        } catch {
            throw .notWritten
        }
        var written = 0
        do {
            var fileIndex = 0
            for part in body.parts {
                let head = body.head(of: part)
                try add(head, to: path, files, protection, &written)
                if case .field(_, let value) = part {
                    try add(Data(value.utf8), to: path, files, protection, &written)
                }
                if case .file(_, let fileName, _, _) = part {
                    let data: Data?
                    do {
                        data = try files.read(contents[fileIndex])
                    } catch {
                        throw MultipartFileProblem.locked
                    }
                    guard let data else { throw MultipartFileProblem.attachmentMissing(fileName: fileName) }
                    try add(data, to: path, files, protection, &written)
                    fileIndex += 1
                }
                try add(MultipartBody.partEnd, to: path, files, protection, &written)
            }
            try add(body.closing, to: path, files, protection, &written)
        } catch {
            removeQuietly(path, from: files)
            throw error as? MultipartFileProblem ?? .notWritten
        }
        return written
    }

    private static func add(
        _ data: Data, to path: String, _ files: any FileStore, _ protection: FileProtection, _ written: inout Int
    ) throws(MultipartFileProblem) {
        do {
            try files.append(data, to: path, protection: protection)
        } catch {
            throw .notWritten
        }
        written += data.count
    }

    private static func removeQuietly(_ path: String, from files: any FileStore) {
        do { try files.remove(path) } catch {}
    }
}
