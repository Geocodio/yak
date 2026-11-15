import Testing

@testable import YakKit

@Suite struct FilesModeTests {
    @Test func initialModeFollowsChanges() {
        let both: [FilesMode] = [.changed, .everything]
        #expect(FilesMode.initial(hasChanges: true, built: both) == .changed)
        #expect(FilesMode.initial(hasChanges: false, built: both) == .everything)
        #expect(FilesMode.initial(hasChanges: true, built: [.everything]) == .everything)
        #expect(FilesMode.initial(hasChanges: false, built: [.changed]) == .changed)
    }

    @Test func aSingleModeIsAlwaysChosen() {
        #expect(FilesMode.initial(hasChanges: true, built: [.changed]) == .changed)
        #expect(FilesMode.initial(hasChanges: false, built: [.changed]) == .changed)
        #expect(FilesMode.initial(hasChanges: true, built: [.everything]) == .everything)
        #expect(FilesMode.initial(hasChanges: false, built: [.everything]) == .everything)
    }

    @Test func nothingBuiltNeverCrashes() {
        #expect(FilesMode.initial(hasChanges: true, built: []) == .changed)
        #expect(FilesMode.initial(hasChanges: false, built: []) == .changed)
    }

    @Test func theDefaultBuiltListIsUsedWhenOmitted() {
        #expect(FilesMode.initial(hasChanges: false) == FilesMode.initial(hasChanges: false, built: FilesMode.built))
    }
}
