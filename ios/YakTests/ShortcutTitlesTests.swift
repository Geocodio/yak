import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct ShortcutTitlesTests {
    @Test func everyShortcutHasATitle() {
        for shortcut in AppShortcut.allCases {
            let title = String(localized: shortcut.title)
            #expect(!title.isEmpty && !title.hasPrefix("shortcut."), "\(shortcut) has no title in the catalog")
        }
    }

    @Test func titlesAreUnique() {
        let titles = AppShortcut.allCases.map { String(localized: $0.title) }
        #expect(Set(titles).count == titles.count)
    }
}
