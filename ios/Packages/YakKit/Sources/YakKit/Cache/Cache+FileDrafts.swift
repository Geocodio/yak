import Foundation

extension Cache {
    /// One file per thread under `file-drafts/`, beside the thread folders so the eviction never removes it.
    private func draftsPath(_ id: String) -> String { "\(root)/file-drafts/\(Endpoint<ThreadShow>.segment(id)).json" }

    private func draftKey(_ scope: FileScope, _ path: String) -> String { "\(scope.rawValue):\(path)" }

    public func fileEditDraft(thread id: String, scope: FileScope, path: String) -> FileEditDraft? {
        load([String: FileEditDraft].self, at: draftsPath(id))?[draftKey(scope, path)]
    }

    /// A text over `FileEditDraft.maximumBytes` is not stored.
    public func saveFileEditDraft(_ draft: FileEditDraft, thread id: String) {
        guard draft.text.utf8.count <= FileEditDraft.maximumBytes else { return }
        var drafts = load([String: FileEditDraft].self, at: draftsPath(id)) ?? [:]
        drafts[draftKey(draft.scope, draft.path)] = draft
        store(drafts, at: draftsPath(id), protection: .complete)
    }

    public func removeFileEditDraft(thread id: String, scope: FileScope, path: String) {
        guard var drafts = load([String: FileEditDraft].self, at: draftsPath(id)) else { return }
        guard drafts.removeValue(forKey: draftKey(scope, path)) != nil else { return }
        if drafts.isEmpty {
            delete(draftsPath(id))
        } else {
            store(drafts, at: draftsPath(id), protection: .complete)
        }
    }
}
