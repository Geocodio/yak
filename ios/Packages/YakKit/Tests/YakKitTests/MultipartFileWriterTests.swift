import Foundation
import Testing

@testable import YakKit

private let oneData = Data(repeating: 7, count: 300_000)
private let twoData = Data("%PDF-1.7".utf8)

private func parts(dataOnFiles: Bool) -> [MultipartBody.Part] {
    [
        .field(name: "message", value: "Look at these"), .field(name: "send_key", value: "K"),
        .file(
            name: "attachments[]", fileName: "one.jpg", contentType: "image/jpeg", data: dataOnFiles ? oneData : Data()),
        .file(
            name: "attachments[]", fileName: "two.pdf", contentType: "application/pdf",
            data: dataOnFiles ? twoData : Data()),
        .field(name: "attachment_refs[]", value: "Image #1"),
    ]
}

private func storeWithFiles() throws -> InMemoryFileStore {
    let files = InMemoryFileStore()
    try files.write(oneData, to: "a/one.jpg", protection: .untilFirstUnlock)
    try files.write(twoData, to: "a/two.pdf", protection: .untilFirstUnlock)
    return files
}

private let placeholders = MultipartBody(parts: parts(dataOnFiles: false), boundary: "yak-K")

@Test func theBodyFileHasTheSameBytesAsTheEncodedBody() throws {
    let files = try storeWithFiles()
    let full = MultipartBody(parts: parts(dataOnFiles: true), boundary: "yak-K")
    let written = try MultipartFileWriter.write(
        placeholders, contents: ["a/one.jpg", "a/two.pdf"], from: files, to: "a/body", protection: .untilFirstUnlock)
    #expect(try files.read("a/body") == full.encoded())
    #expect(written == full.encoded().count)
    #expect(files.protection(of: "a/body") == .untilFirstUnlock)
}

@Test func aMissingAttachmentIsNamedAndNothingIsLeft() throws {
    let files = InMemoryFileStore()
    try files.write(oneData, to: "a/one.jpg", protection: .untilFirstUnlock)
    #expect(throws: MultipartFileProblem.attachmentMissing(fileName: "two.pdf")) {
        try MultipartFileWriter.write(
            placeholders, contents: ["a/one.jpg", "a/two.pdf"], from: files, to: "a/body", protection: .untilFirstUnlock
        )
    }
    #expect(try files.read("a/body") == nil)
}

private final class LockedStore: FileStore, @unchecked Sendable {
    let base = InMemoryFileStore()
    func read(_ path: String) throws(FileStoreError) -> Data? {
        if path == "a/two.pdf" { throw .unreadable }
        return try base.read(path)
    }
    func write(_ data: Data, to path: String, protection: FileProtection) throws(FileStoreError) {
        try base.write(data, to: path, protection: protection)
    }
    func remove(_ path: String) throws(FileStoreError) { try base.remove(path) }
    func list(_ folder: String) throws(FileStoreError) -> [String] { try base.list(folder) }
}

@Test func aLockedAttachmentWaits() throws {
    let files = LockedStore()
    try files.write(oneData, to: "a/one.jpg", protection: .untilFirstUnlock)
    try files.write(twoData, to: "a/two.pdf", protection: .untilFirstUnlock)
    #expect(throws: MultipartFileProblem.locked) {
        try MultipartFileWriter.write(
            placeholders, contents: ["a/one.jpg", "a/two.pdf"], from: files, to: "a/body", protection: .untilFirstUnlock
        )
    }
    #expect(try files.read("a/body") == nil)
}

@Test func aCountMismatchIsRefused() throws {
    let files = try storeWithFiles()
    #expect(throws: MultipartFileProblem.partCountMismatch(expected: 2, given: 1)) {
        try MultipartFileWriter.write(
            placeholders, contents: ["a/one.jpg"], from: files, to: "a/body", protection: .untilFirstUnlock)
    }
}

@Test func aSecondWriteReplacesTheFirst() throws {
    let files = try storeWithFiles()
    let contents = ["a/one.jpg", "a/two.pdf"]
    let first = try MultipartFileWriter.write(
        placeholders, contents: contents, from: files, to: "a/body", protection: .untilFirstUnlock)
    let second = try MultipartFileWriter.write(
        placeholders, contents: contents, from: files, to: "a/body", protection: .untilFirstUnlock)
    #expect(first == second)
    #expect(try files.read("a/body")?.count == second)
}
