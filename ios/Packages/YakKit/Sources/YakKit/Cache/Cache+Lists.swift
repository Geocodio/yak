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

public enum TaskListCacheKey: String, Sendable { case observations }

extension Cache {
    private func firstPagePath(_ key: TaskListCacheKey) -> String { "\(root)/task-lists/\(key.rawValue).json" }

    // MARK: Lists

    public func firstPage<Value: Codable & Sendable>(_ key: TaskListCacheKey) -> Value? {
        load(Value.self, at: firstPagePath(key))
    }

    public func saveFirstPage<Value: Codable & Sendable>(_ value: Value, key: TaskListCacheKey) {
        store(value, at: firstPagePath(key), protection: .untilFirstUnlock)
    }
}
