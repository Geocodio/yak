import Foundation

#if canImport(os)
    import os
#endif

/// The two calls the store needs from `UserDefaults`.
public protocol KeyValueStore: Sendable {
    func data(forKey key: String) -> Data?
    func set(_ value: Data?, forKey key: String)
}

// Foundation on Linux marks UserDefaults as non-Sendable; Linux tests use the in-memory store.
#if canImport(Darwin)
    extension UserDefaults: KeyValueStore {
        public func set(_ value: Data?, forKey key: String) {
            set(value as Any?, forKey: key)
        }
    }
#endif

/// Logs a failure message (never stored content). Linux has no `os.Logger`, so it is a no-op there.
private func logFailure(_ message: String) {
    #if canImport(os)
        Logger(subsystem: "YakKit", category: "InstanceStore").error("\(message, privacy: .public)")
    #endif
}

public enum InstanceState: Equatable, Sendable {
    case ready, needsPairing
    /// The Keychain failed with something other than "no such item", for example while locked.
    case tokenUnreadable
}

public enum InstanceStoreError: Error, Equatable, Sendable {
    case unknownInstance
}

public actor InstanceStore {
    private static let instancesKey = "instances.v1"
    private static let activeKey = "instances.active"

    private let defaults: KeyValueStore
    private let tokens: TokenStore
    private let now: @Sendable () -> Date
    private var instances: [Instance]
    private var activeID: UUID?

    public init(defaults: KeyValueStore, tokens: TokenStore, now: @escaping @Sendable () -> Date) {
        self.defaults = defaults
        self.tokens = tokens
        self.now = now
        self.instances = Self.loadInstances(from: defaults)
        self.activeID = defaults.data(forKey: Self.activeKey)
            .flatMap { String(data: $0, encoding: .utf8) }
            .flatMap(UUID.init(uuidString:))
    }

    /// An unreadable list is treated as empty: the person pairs again instead of the app crashing.
    /// The failure is logged without any stored content.
    private static func loadInstances(from defaults: KeyValueStore) -> [Instance] {
        guard let data = defaults.data(forKey: instancesKey) else { return [] }
        do {
            return try JSONDecoder().decode([Instance].self, from: data)
        } catch {
            logFailure("Stored instance list is unreadable, starting empty: \(type(of: error))")
            return []
        }
    }

    /// Most recently used first.
    public func all() -> [Instance] {
        instances.sorted { $0.lastUsedAt > $1.lastUsedAt }
    }

    public func active() -> Instance? {
        instances.first { $0.id == activeID } ?? all().first
    }

    /// `.needsPairing` when the token is missing, `.tokenUnreadable` when reading it failed (for
    /// example the Keychain being locked before first unlock). The entry and its token are left
    /// untouched either way.
    public func state(of instance: UUID) -> InstanceState {
        do {
            guard let token = try tokens.token(for: instance), !token.isEmpty else {
                return .needsPairing
            }
            return .ready
        } catch {
            logFailure("Token read failed: \(type(of: error))")
            return .tokenUnreadable
        }
    }

    /// An entry with the same URL is replaced in place, so there is never a duplicate row.
    @discardableResult
    public func add(url: URL, name: String, userName: String, token: String) throws -> Instance {
        let existingIndex = instances.firstIndex { $0.url == url }
        var instance =
            existingIndex.map { instances[$0] }
            ?? Instance(url: url, name: name, userName: userName, lastUsedAt: now())
        instance.name = name
        instance.userName = userName
        instance.lastUsedAt = now()
        try tokens.setToken(token, for: instance.id)
        if let existingIndex {
            instances[existingIndex] = instance
        } else {
            instances.append(instance)
        }
        activeID = instance.id
        try persist()
        return instance
    }

    public func update(_ instance: UUID, from me: Me) throws {
        guard let index = instances.firstIndex(where: { $0.id == instance }) else {
            throw InstanceStoreError.unknownInstance
        }
        instances[index].name = me.instance.name
        instances[index].userName = me.user.name
        instances[index].needsYouCount = me.needsYouCount
        instances[index].pushAvailable = me.push.apns
        try persist()
    }

    public func setActive(_ instance: UUID) throws {
        guard let index = instances.firstIndex(where: { $0.id == instance }) else {
            throw InstanceStoreError.unknownInstance
        }
        instances[index].lastUsedAt = now()
        activeID = instance
        try persist()
    }

    /// Removes the token and keeps the entry, so the list offers "Pair again".
    public func markRevoked(_ instance: UUID) throws {
        guard instances.contains(where: { $0.id == instance }) else {
            throw InstanceStoreError.unknownInstance
        }
        try tokens.removeToken(for: instance)
    }

    public func remove(_ instance: UUID) throws {
        try tokens.removeToken(for: instance)
        instances.removeAll { $0.id == instance }
        if activeID == instance { activeID = nil }
        try persist()
    }

    private func persist() throws {
        defaults.set(try JSONEncoder().encode(instances), forKey: Self.instancesKey)
        defaults.set(activeID.map { Data($0.uuidString.utf8) }, forKey: Self.activeKey)
    }
}
