import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct EditFromTheDiffTests {
    private func file(
        status: DiffFileStatus = .modified, isBinary: Bool = false, isTooLarge: Bool = false
    ) -> DiffFileSummary {
        DiffFileSummary(
            id: 1, repo: "example/api", path: "app/A.php", status: status, additions: 1, deletions: 1,
            isBinary: isBinary, isTooLarge: isTooLarge)
    }

    private func can(
        _ file: DiffFileSummary, state: FilePageState? = .noContentChanges, canDrive: Bool = true,
        filesAvailable: Bool = true
    ) -> Bool {
        FilePageView.canEdit(file: file, state: state, canDrive: canDrive, filesAvailable: filesAvailable)
    }

    @Test func aDriverCanEditAModifiedOrAddedTextFile() {
        #expect(can(file()))
        #expect(can(file(status: .added)))
        #expect(can(file(), state: .loading))
    }

    @Test func nobodyEditsWhatTheEditorCannotOpen() {
        #expect(!can(file(status: .deleted)))
        #expect(!can(file(isBinary: true)))
        #expect(!can(file(isTooLarge: true)))
        #expect(!can(file(), state: .binary))
        #expect(!can(file(), state: .tooLarge))
        #expect(!can(file(), state: .gone))
    }

    @Test func aReaderOrAThreadWithoutFilesGetsNoButton() {
        #expect(!can(file(), canDrive: false))
        #expect(!can(file(), filesAvailable: false))
    }
}
