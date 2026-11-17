import CoreSpotlight
import Foundation
import UniformTypeIdentifiers
import YakKit

/// Applies one change of the plan to the index.
protocol SpotlightWriter: Sendable {
    func apply(_ change: SpotlightChange) async throws
}

/// The only place an entry becomes a Core Spotlight item.
struct CoreSpotlightWriter: SpotlightWriter {
    static let indexName = "threads"

    /// Title, instance name, identifier, domain and expiry; nothing else (no keywords, thumbnail or address).
    static func item(for entry: SpotlightEntry) -> CSSearchableItem {
        let attributes = CSSearchableItemAttributeSet(contentType: .item)
        attributes.title = entry.title
        attributes.displayName = entry.title
        attributes.contentDescription = entry.instanceName
        let item = CSSearchableItem(
            uniqueIdentifier: entry.identifier, domainIdentifier: entry.domain, attributeSet: attributes)
        item.expirationDate = entry.expiresAt
        return item
    }

    static func makeIndex() -> CSSearchableIndex {
        CSSearchableIndex(name: indexName, protectionClass: .completeUntilFirstUserAuthentication)
    }

    func apply(_ change: SpotlightChange) async throws {
        let index = Self.makeIndex()
        switch change {
        case .eraseAll:
            try await index.deleteAllSearchableItems()
        case .erase(let domains):
            try await index.deleteSearchableItems(withDomainIdentifiers: domains.map(\.uuidString))
        case .replace(let domain, let entries):
            try await index.deleteSearchableItems(withDomainIdentifiers: [domain.uuidString])
            if !entries.isEmpty { try await index.indexSearchableItems(entries.map(Self.item(for:))) }
        }
    }
}
