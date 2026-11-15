import Foundation

/// The cacheable first page of a paged list.
public struct FirstPage<Row: Codable & Sendable>: Codable, Sendable {
    public var rows: [Row]
    public var nextPage: Int?

    public init(rows: [Row], nextPage: Int?) {
        self.rows = rows
        self.nextPage = nextPage
    }
}

extension FirstPage: Equatable where Row: Equatable {}

public enum TaskListCacheKey: String, Sendable { case tasks, observations }

extension Cache {
    public static let maximumTasks = 50

    private func firstPagePath(_ key: TaskListCacheKey) -> String { "\(root)/task-lists/\(key.rawValue).json" }
    private var taskIndexPath: String { "\(root)/tasks/index.json" }
    private func taskFolder(_ id: String) -> String { "\(root)/tasks/\(Endpoint<AppTask>.segment(id))" }

    // MARK: Lists

    public func firstPage<Value: Codable & Sendable>(_ key: TaskListCacheKey) -> Value? {
        load(Value.self, at: firstPagePath(key))
    }

    public func saveFirstPage<Value: Codable & Sendable>(_ value: Value, key: TaskListCacheKey) {
        store(value, at: firstPagePath(key), protection: .untilFirstUnlock)
    }

    // MARK: Tasks

    public func task(_ id: String) -> AppTask? {
        load(AppTask.self, at: "\(taskFolder(id))/task.json")
    }

    /// Saves the copy and keeps the newest `maximumTasks`; threads have their own index and are never evicted by this one.
    public func saveTask(_ task: AppTask, id: String) {
        store(task, at: "\(taskFolder(id))/task.json", protection: .complete)
        var index = loadTaskIndex()
        index[id] = clockNow()
        if index.count > Self.maximumTasks {
            let stale = index.filter { $0.key != id }
                .sorted { ($0.value, $0.key) < ($1.value, $1.key) }
                .prefix(index.count - Self.maximumTasks)
            for entry in stale {
                delete(taskFolder(entry.key))
                index[entry.key] = nil
            }
        }
        store(index, at: taskIndexPath, protection: .untilFirstUnlock)
    }

    /// The folder (copy and draft) and the index entry.
    public func removeTask(_ id: String) {
        delete(taskFolder(id))
        var index = loadTaskIndex()
        if index.removeValue(forKey: id) != nil { store(index, at: taskIndexPath, protection: .untilFirstUnlock) }
    }

    public func taskQuestionDraft(_ id: String) -> QuestionDraft? {
        load(QuestionDraft.self, at: "\(taskFolder(id))/question.json")
    }

    /// Nil removes the draft.
    public func saveTaskQuestionDraft(_ draft: QuestionDraft?, id: String) {
        let path = "\(taskFolder(id))/question.json"
        if let draft {
            store(draft, at: path, protection: .complete)
        } else {
            delete(path)
        }
    }

    private func loadTaskIndex() -> [String: Date] {
        load([String: Date].self, at: taskIndexPath) ?? [:]
    }
}
