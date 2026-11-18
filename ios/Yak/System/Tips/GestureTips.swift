import SwiftUI
import TipKit

#if canImport(os)
    import os
#endif

/// Press and slide on the pane button. Shows once, while the thread has two or more panes.
struct PaneSlideTip: Tip {
    @Parameter static var isEligible: Bool = false

    var id: String { "tip.paneSlide" }
    var title: Text { Text("tip.paneSlide.title") }
    var message: Text? { Text("tip.paneSlide.message") }
    var rules: [Rule] { #Rule(Self.$isEligible) { $0 == true } }
    var options: [any TipOption] { MaxDisplayCount(1) }

    /// The first pick from the pane menu counts as learned.
    @MainActor static func recordUse() { PaneSlideTip().invalidate(reason: .actionPerformed) }
}

/// Holding Send while a turn runs. Shows once, while the field has text and a turn runs.
struct SendAfterTurnTip: Tip {
    @Parameter static var isEligible: Bool = false

    var id: String { "tip.sendAfterTurn" }
    var title: Text { Text("tip.sendAfterTurn.title") }
    var message: Text? { Text("tip.sendAfterTurn.message") }
    var rules: [Rule] { #Rule(Self.$isEligible) { $0 == true } }
    var options: [any TipOption] { MaxDisplayCount(1) }

    @MainActor static func recordUse() { SendAfterTurnTip().invalidate(reason: .actionPerformed) }
}

enum GestureTips {
    enum Mode { case normal, hidden, shown, resetThenNormal }

    /// Once, before any tip view. `resetThenNormal` clears what was shown, so every tip can appear again.
    @MainActor static func configure(_ mode: Mode) {
        do {
            switch mode {
            case .normal: break
            case .hidden: Tips.hideAllTipsForTesting()
            case .shown: Tips.showAllTipsForTesting()
            case .resetThenNormal: try Tips.resetDatastore()
            }
            try Tips.configure([.displayFrequency(.immediate)])
        } catch {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Tips")
                    .error("Tips could not be set up: \(String(describing: type(of: error)), privacy: .public)")
            #endif
        }
    }

    static func eligibleForPaneSlide(usesMenuButton: Bool, paneCount: Int) -> Bool {
        usesMenuButton && paneCount >= 2
    }

    static func eligibleForSendAfterTurn(offersSendAfterTurn: Bool, hasText: Bool) -> Bool {
        offersSendAfterTurn && hasText
    }
}
