import Foundation
import Testing

@testable import Yak

@Suite struct LicenseListTests {
    @Test func everyEntryHasItsText() {
        for entry in LicenseList.entries {
            let text = LicenseList.text(for: entry, bundle: .main)
            #expect(text?.isEmpty == false, "\(entry.id) has no text in the bundle")
        }
    }

    @Test func aMissingTextIsNil() {
        let entry = LicenseEntry(id: "x", name: "x", licenseName: "x", resource: "no-such-file")
        #expect(LicenseList.text(for: entry, bundle: .main) == nil)
    }

    @Test func noTwoEntriesShareAnID() {
        let ids = LicenseList.entries.map(\.id)
        #expect(Set(ids).count == ids.count)
    }
}
