import SwiftUI
import UIKit

/// What the Terminal pane needs from an emulator. There is one implementation; the pane never imports the package.
@MainActor
protocol TerminalSurface: AnyObject {
    var view: UIView { get }
    /// Keys typed into the surface, the hardware keyboard included.
    var onInput: (([UInt8]) -> Void)? { get set }
    var onSizeChange: ((_ columns: Int, _ rows: Int) -> Void)? { get set }
    /// Called when the scroll position moves to or away from the end.
    var onScrollPositionChange: ((_ isAtEnd: Bool) -> Void)? { get set }
    var isApplicationCursorMode: Bool { get }
    /// The visible lines, for VoiceOver and UI tests.
    var visibleText: String { get }
    var isScrolledToEnd: Bool { get }
    /// Batched by the caller, at most once per frame.
    func feed(_ bytes: Data)
    /// A dim full-width line that is never sent to the shell.
    func feedDivider(_ text: String)
    func scrollToEnd()
    func setFontSize(_ points: CGFloat)
    /// Shows the software keyboard or takes the hardware one.
    func focus()
    func resignFocus()
}

/// The font sizes of the terminal: the body size scaled by Dynamic Type up to a cap, and the range of pinch to zoom.
enum TerminalFontSize {
    static let baseSize: CGFloat = 14
    static let cap: CGFloat = 24
    static let range: ClosedRange<CGFloat> = 9...32

    @MainActor static func scaled(for traits: UITraitCollection) -> CGFloat {
        min(cap, UIFontMetrics(forTextStyle: .body).scaledValue(for: baseSize, compatibleWith: traits))
    }
}

/// Hosts a `TerminalSurface` in SwiftUI. The surface keeps its own state, so the view only follows Dynamic Type:
/// the scaled size applies when the content size category changes, and a pinched size stays through other updates.
struct TerminalSurfaceView: UIViewRepresentable {
    let surface: any TerminalSurface

    final class Coordinator {
        var category: UIContentSizeCategory?
    }

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> UIView { surface.view }

    func updateUIView(_ view: UIView, context: Context) {
        let category = view.traitCollection.preferredContentSizeCategory
        guard context.coordinator.category != category else { return }
        context.coordinator.category = category
        surface.setFontSize(TerminalFontSize.scaled(for: view.traitCollection))
    }
}
