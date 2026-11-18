import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct LiveActivityStringsTests {
    @Test func theDuoPillLabelIsAtMostThreeCharacters() {
        let brand = String(localized: LiveActivityText.brand)
        #expect(!brand.isEmpty)
        #expect(brand.count <= 3)
    }

    @Test func everyLookHasItsStrings() {
        let details: [LiveActivityDetail] = [
            .step("Running a command"), .working, .needsYou, .finished, .failed, .stopped, .noRecentUpdate,
        ]
        for detail in details {
            let text = String(localized: LiveActivityText.text(for: detail))
            #expect(!text.isEmpty)
            #expect(!text.hasPrefix("liveActivity."), "\(detail) shows its key")
        }
        let glyphs: [LiveActivityGlyph] = [.working, .needsYou, .finished, .failed, .stopped, .stale]
        for glyph in glyphs {
            for resource in [LiveActivityText.label(for: glyph), LiveActivityText.word(for: glyph)] {
                let text = String(localized: resource)
                #expect(!text.isEmpty)
                #expect(!text.hasPrefix("liveActivity."), "\(glyph) shows its key")
            }
        }
        #expect(String(localized: LiveActivityText.header(host: "yak.example.com")) == "Yak, yak.example.com")
    }

    @Test func aPrivateThreadReadsAsOne() {
        #expect(String(localized: LiveActivityText.privateTitle) == "A private thread")
    }

    @Test func aStepIsShownAsWritten() {
        #expect(String(localized: LiveActivityText.text(for: .step("Running a command"))) == "Running a command")
    }
}
