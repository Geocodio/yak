extension Cache {
    private func diffPath(_ id: String) -> String { "\(folder(id))/diff.json" }
    private func viewedPath(_ id: String) -> String { "\(folder(id))/viewed.json" }

    public func diff(thread id: String) -> ThreadDiff? {
        load(ThreadDiff.self, at: diffPath(id))
    }

    /// Nil removes the file.
    public func saveDiff(_ diff: ThreadDiff?, thread id: String) {
        if let diff {
            store(diff, at: diffPath(id), protection: .complete)
            touch(id, addingIfMissing: true)
        } else {
            delete(diffPath(id))
        }
    }

    public func viewedMarks(thread id: String) -> ViewedMarks {
        load(ViewedMarks.self, at: viewedPath(id)) ?? ViewedMarks()
    }

    public func saveViewedMarks(_ marks: ViewedMarks, thread id: String) {
        store(marks, at: viewedPath(id), protection: .complete)
        touch(id, addingIfMissing: true)
    }
}
