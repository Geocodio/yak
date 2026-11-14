#if canImport(Security)
import Foundation
import Security

public struct KeychainError: Error, Equatable, Sendable {
    public let status: OSStatus
}

/// Tokens in the Keychain: service `yak-app`, account = the instance id,
/// available after first unlock and never copied to another device.
public struct KeychainTokenStore: TokenStore {
    private let accessGroup: String?
    private static let service = "yak-app"

    public init(accessGroup: String?) {
        self.accessGroup = accessGroup
    }

    private func query(for instance: UUID) -> [String: Any] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: Self.service,
            kSecAttrAccount as String: instance.uuidString,
        ]
        if let accessGroup {
            query[kSecAttrAccessGroup as String] = accessGroup
        }
        return query
    }

    public func token(for instance: UUID) throws -> String? {
        var query = query(for: instance)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess else { throw KeychainError(status: status) }
        guard let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    public func setToken(_ token: String, for instance: UUID) throws {
        let data = Data(token.utf8)
        let attributes: [String: Any] = [kSecValueData as String: data]
        var status = SecItemUpdate(query(for: instance) as CFDictionary, attributes as CFDictionary)
        if status == errSecItemNotFound {
            var item = query(for: instance)
            item[kSecValueData as String] = data
            item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
            status = SecItemAdd(item as CFDictionary, nil)
        }
        guard status == errSecSuccess else { throw KeychainError(status: status) }
    }

    public func removeToken(for instance: UUID) throws {
        let status = SecItemDelete(query(for: instance) as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw KeychainError(status: status)
        }
    }
}
#endif
