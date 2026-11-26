import SwiftUI
import YakKit

/// The words, mark and color of a sub-thread's state, from the one mapping in the kit. The Overview group and the
/// drawer's sub-thread rows both read them here.
extension SubThreadStateStyle.Word {
    var text: String {
        switch self {
        case .starting: String(localized: "subThread.state.starting")
        case .waiting: String(localized: "subThread.state.waiting")
        case .working: String(localized: "subThread.state.working")
        case .needsYou: String(localized: "subThread.state.needsYou")
        case .done: String(localized: "subThread.state.done")
        case .failed: String(localized: "subThread.state.failed")
        case .stopped: String(localized: "subThread.state.stopped")
        case .takenOver: String(localized: "subThread.state.takenOver")
        case .other: String(localized: "subThread.state.other")
        }
    }
}

extension SubThreadStateStyle.Tone {
    var color: Color {
        switch self {
        case .brand: .yakSlate
        case .quiet: .yakFaint
        case .attention: .yakWarningDot
        case .failure: .yakFailure
        }
    }
}

/// The dot before a sub-thread's name: filled while it is live or needs the person, a ring while it is not running.
/// The state's word always says the same, so the color is never the only signal.
struct SubThreadMark: View {
    let style: SubThreadStateStyle

    var body: some View {
        Group {
            switch style.mark {
            case .filled: Circle().fill(style.tone.color)
            case .ring: Circle().strokeBorder(style.tone.color, lineWidth: Design.Size.markRing)
            }
        }
        .frame(width: Design.Size.statusDot, height: Design.Size.statusDot)
        .accessibilityHidden(true)
    }
}

extension StackChildKind {
    var text: String {
        switch self {
        case .implement: String(localized: "subThread.kind.implement")
        case .research: String(localized: "subThread.kind.research")
        case .mockup: String(localized: "subThread.kind.mockup")
        case .general: String(localized: "subThread.kind.general")
        default: rawValue.prefix(1).uppercased() + rawValue.dropFirst()
        }
    }
}
