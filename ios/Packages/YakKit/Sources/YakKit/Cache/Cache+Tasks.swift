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

    /// The saved copy of the task, found by its own id or by a child id that opened it.
    public func task(_ id: String) -> AppTask? {
        load(AppTask.self, at: "\(taskFolder(resolvedTaskID(id)))/task.json")
    }

    /// Saves the copy and keeps the newest `maximumTasks`; threads have their own index and are never evicted by this one.
    /// `askedID` is the id the screen opened by when it differs from the root's `id`; it finds the copy later.
    public func saveTask(_ task: AppTask, id: String, askedID: String? = nil) {
        store(task, at: "\(taskFolder(id))/task.json", protection: .complete)
        var index = loadTaskIndex()
        index.counter += 1
        index.order[id] = index.counter
        if let askedID, askedID != id { index.aliases[askedID] = id }
        if index.order.count > Self.maximumTasks {
            let stale = index.order.filter { $0.key != id }
                .sorted { ($0.value, $0.key) < ($1.value, $1.key) }
                .prefix(index.order.count - Self.maximumTasks)
            for entry in stale {
                delete(taskFolder(entry.key))
                index.order[entry.key] = nil
                index.aliases = index.aliases.filter { $0.value != entry.key }
            }
        }
        store(index, at: taskIndexPath, protection: .untilFirstUnlock)
    }

    /// The folder (copy and draft), the index entry and every child id that pointed at it.
    public func removeTask(_ id: String) {
        var index = loadTaskIndex()
        let rootID = index.aliases[id] ?? id
        delete(taskFolder(rootID))
        let before = index
        index.order[rootID] = nil
        index.aliases = index.aliases.filter { $0.key != id && $0.value != rootID }
        if index != before { store(index, at: taskIndexPath, protection: .untilFirstUnlock) }
    }

    public func taskQuestionDraft(_ id: String) -> QuestionDraft? {
        load(QuestionDraft.self, at: "\(taskFolder(resolvedTaskID(id)))/question.json")
    }

    /// Nil removes the draft.
    public func saveTaskQuestionDraft(_ draft: QuestionDraft?, id: String) {
        let path = "\(taskFolder(resolvedTaskID(id)))/question.json"
        if let draft {
            store(draft, at: path, protection: .complete)
        } else {
            delete(path)
        }
    }

    private func resolvedTaskID(_ id: String) -> String { loadTaskIndex().aliases[id] ?? id }

    private func loadTaskIndex() -> TaskIndex {
        load(TaskIndex.self, at: taskIndexPath) ?? TaskIndex()
    }
}

/// Which saved task is newest: a counter that grows with every save, so no clock is needed. `aliases` maps a child
/// task id to the root id its copy is saved under.
private struct TaskIndex: Codable, Equatable {
    var counter = 0
    var order: [String: Int] = [:]
    var aliases: [String: String] = [:]
}
