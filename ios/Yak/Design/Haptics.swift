import SwiftUI
import UIKit

/// The haptic the app plays for each kind of moment. A view asks for a moment by name; the style behind it lives here,
/// so no view creates a feedback generator of its own.
enum Haptic: CaseIterable {
    /// The drawer has settled open.
    case drawerSettled
    /// The focused pane changed.
    case paneChanged
    /// Another thread opened.
    case threadSwitched
    /// A message was accepted for sending.
    case sent
    /// A pull to refresh ended, whether it loaded or not.
    case refreshed

    enum Style: Equatable {
        case softImpact, selection, success
    }

    var style: Style {
        switch self {
        case .drawerSettled: .softImpact
        case .paneChanged, .threadSwitched: .selection
        case .sent: .success
        case .refreshed: .softImpact
        }
    }

    var feedback: SensoryFeedback {
        switch style {
        case .softImpact: .impact(weight: .light)
        case .selection: .selection
        case .success: .success
        }
    }

    /// Plays the haptic from code that has no view, such as a model.
    @MainActor func play() {
        switch style {
        case .softImpact: UIImpactFeedbackGenerator(style: .soft).impactOccurred()
        case .selection: UISelectionFeedbackGenerator().selectionChanged()
        case .success: UINotificationFeedbackGenerator().notificationOccurred(.success)
        }
    }
}

extension View {
    /// Plays `haptic` when `trigger` changes.
    func haptic<Trigger: Equatable>(_ haptic: Haptic, trigger: Trigger) -> some View {
        sensoryFeedback(haptic.feedback, trigger: trigger)
    }

    /// Plays `haptic` when `trigger` changes and `condition` accepts the change.
    func haptic<Trigger: Equatable>(
        _ haptic: Haptic, trigger: Trigger, where condition: @escaping (Trigger, Trigger) -> Bool
    ) -> some View {
        sensoryFeedback(haptic.feedback, trigger: trigger, condition: condition)
    }
}

extension View {
    /// Pull to refresh that ticks when the refresh has finished.
    func refreshableWithHaptic(_ action: @escaping @Sendable () async -> Void) -> some View {
        refreshable {
            await action()
            Haptic.refreshed.play()
        }
    }
}
