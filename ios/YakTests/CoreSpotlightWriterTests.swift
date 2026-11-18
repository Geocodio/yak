import CoreSpotlight
import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct CoreSpotlightWriterTests {
    @Test func anItemCarriesOnlyTitleInstanceAndExpiry() {
        let entry = SpotlightEntry(
            identifier: "\(UUID().uuidString)/d0e515ef", domain: UUID().uuidString,
            title: "Retry failed rows when the import times out", instanceName: "Example Yak",
            expiresAt: Date(timeIntervalSince1970: 2_000_000_000))
        let item = CoreSpotlightWriter.item(for: entry)
        #expect(item.uniqueIdentifier == entry.identifier)
        #expect(item.domainIdentifier == entry.domain)
        #expect(item.expirationDate == entry.expiresAt)
        let attributes = item.attributeSet
        #expect(attributes.title == entry.title)
        #expect(attributes.displayName == entry.title)
        #expect(attributes.contentDescription == "Example Yak")
        #expect(attributes.keywords?.isEmpty ?? true)
        #expect(attributes.thumbnailData == nil)
        #expect(attributes.thumbnailURL == nil)
        #expect(attributes.contentURL == nil)
        #expect(attributes.relatedUniqueIdentifier == nil)
    }
}
