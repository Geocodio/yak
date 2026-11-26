import SwiftUI
import Testing

@testable import Yak

/// The source badge on a drawer row hugs a short label and cuts a long one at its cap, with standard modifiers only.
@MainActor struct DrawerBadgeWidthTests {
    private func width(of text: String, offered: CGFloat = 400) -> CGFloat {
        let host = UIHostingController(rootView: DrawerBadge(text: text))
        return host.sizeThatFits(in: CGSize(width: offered, height: 200)).width
    }

    private let longLabel = "A channel label from a much newer server version"

    @Test func aLongLabelStopsAtTheCap() {
        #expect(width(of: longLabel) <= Design.Size.badgeMaxWidth + 1)
        #expect(width(of: longLabel) >= Design.Size.badgeMaxWidth - 1)
    }

    @Test func aShortLabelHugsItsText() {
        let slack = width(of: "Slack")
        let pagerDuty = width(of: "PagerDuty")
        #expect(slack < pagerDuty)
        #expect(pagerDuty < Design.Size.badgeMaxWidth)
    }
}
