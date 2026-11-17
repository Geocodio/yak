import Testing

@testable import Yak

@Suite struct GestureTipsTests {
    @Test func paneSlideNeedsTheMenuButtonAndTwoPanes() {
        for usesMenuButton in [true, false] {
            for count in 0...3 {
                let expected = usesMenuButton && count >= 2
                #expect(GestureTips.eligibleForPaneSlide(usesMenuButton: usesMenuButton, paneCount: count) == expected)
            }
        }
    }

    @Test func sendAfterTurnNeedsARunningTurnAndText() {
        for offers in [true, false] {
            for hasText in [true, false] {
                #expect(
                    GestureTips.eligibleForSendAfterTurn(offersSendAfterTurn: offers, hasText: hasText)
                        == (offers && hasText))
            }
        }
    }
}
