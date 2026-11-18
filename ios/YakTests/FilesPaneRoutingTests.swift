import Testing
import YakKit

@testable import Yak

@MainActor @Test func aDiffRequestFindsTheFileByPath() {
    let files = [
        DiffFileSummary(id: 1, repo: "example/web", path: "src/Importer.php", status: .modified),
        DiffFileSummary(id: 2, repo: "example/web", path: "README.md", status: .added),
    ]
    #expect(FilesPane.diffKey(for: "README.md", in: files) == DiffFileKey(repo: "example/web", path: "README.md"))
    #expect(FilesPane.diffKey(for: "missing.txt", in: files) == nil)
}
