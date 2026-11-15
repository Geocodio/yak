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

    /// Reads, checks and writes in one step, so no other call lands between them. Writes the text, kind and model
    /// and keeps the saved key and mark. Does nothing while the saved draft is in flight. Nil or an empty draft
    /// removes the saved one.
    func mergeSubThreadDraft(_ draft: SubThreadDraft?, parentID: String) {
        let saved = subThreadDraft(parentID: parentID)
        if saved?.sendKeyIsInFlight == true { return }
        guard var draft, !draft.isEmpty else {
            saveSubThreadDraft(nil, parentID: parentID)
            return
        }
        draft.sendKey = saved?.sendKey
        draft.sendKeyIsInFlight = false
        saveSubThreadDraft(draft, parentID: parentID)
    }
}
