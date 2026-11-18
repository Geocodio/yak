import SwiftUI
import Testing
import UIKit

@testable import Yak

@MainActor @Suite struct DebugGalleryTests {
    @Test func entryIDsAreUnique() {
        let ids = DebugGallery.entries.map(\.id)
        #expect(Set(ids).count == ids.count)
        #expect(!ids.isEmpty)
    }

    @Test func everyTitleAndAreaIsInTheStringCatalog() {
        for entry in DebugGallery.entries {
            let key = "gallery.title." + entry.id
            #expect(String(localized: String.LocalizationValue(key)) != key, "\(key)")
        }
        for area in DebugGallery.areas {
            let key = "gallery.area." + area
            #expect(String(localized: String.LocalizationValue(key)) != key, "\(key)")
        }
    }

    @Test func everyAreaIsKnown() {
        for entry in DebugGallery.entries { #expect(DebugGallery.areas.contains(entry.area), "\(entry.id)") }
    }

    @Test func everyEntryBuilds() {
        for entry in DebugGallery.entries {
            let controller = UIHostingController(rootView: entry.make())
            controller.view.frame = CGRect(x: 0, y: 0, width: 390, height: 844)
            controller.view.layoutIfNeeded()
            #expect(controller.view.bounds.width == 390, "\(entry.id)")
        }
    }

    @Test func noSampleShowsAPrivateTitle() {
        let text = (GallerySamples.allText + DebugGallery.entries.map(\.id)).joined(separator: "\n")
        #expect(!text.contains("Private notes on billing"))
        #expect(!text.contains("Split the billing job"))
        #expect(GallerySamples.privateThreadTitle == "A private thread")
    }
}
