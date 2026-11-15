import Foundation

extension Cache {
    private func subThreadDraftPath(parentID: String) -> String { "\(folder(parentID))/sub-thread.json" }

    /// The saved "add a sub-thread" form of a thread. Nil when none is saved, when it cannot be read (a locked
    /// device) or when it is corrupt.
    func subThreadDraft(parentID: String) -> SubThreadDraft? {
        load(SubThreadDraft.self, at: subThreadDraftPath(parentID: parentID))
    }

    /// Writes the draft and reads it back, so the caller knows the disk holds it. Nil removes it.
    @discardableResult
    func saveSubThreadDraft(_ draft: SubThreadDraft?, parentID: String) -> Bool {
        let path = subThreadDraftPath(parentID: parentID)
        guard let draft else {
            delete(path)
            return true
        }
        store(draft, at: path, protection: .complete)
        return load(SubThreadDraft.self, at: path) == draft
    }
}
