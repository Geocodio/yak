import SwiftUI

/// Named color tokens from the asset catalog. The rust accent is only for the primary button.
extension Color {
    static let yakRust = Color("Rust")
    static let yakOnRust = Color("OnRust")
    /// Links and plain actions: the rust as text color, which passes the contrast bar where the fill color does not.
    static let yakAction = Color("Action")
    static let yakDanger = Color("Danger")
    /// Secondary text. The system's secondary label is too faint for the contrast audit, so this is the primary
    /// label color at 72 percent, which adapts to light and dark.
    static let yakMuted = Color.primary.opacity(0.72)
}

/// The one filled control on a screen.
struct PrimaryButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(Color.yakOnRust)
            .frame(maxWidth: .infinity, minHeight: 44)
            .padding(.vertical, 6)
            .background(Color.yakRust, in: .rect(cornerRadius: 14))
            .opacity(isEnabled ? (configuration.isPressed ? 0.85 : 1) : 0.4)
    }
}

/// A quiet control next to the primary one.
struct SecondaryButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(.primary)
            .frame(maxWidth: .infinity, minHeight: 44)
            .padding(.vertical, 6)
            .background(Color(.systemGray5).opacity(configuration.isPressed ? 0.6 : 1), in: .rect(cornerRadius: 14))
            .opacity(isEnabled ? 1 : 0.5)
    }
}

/// A screen with its actions pinned to the bottom. At accessibility text sizes the actions follow the content
/// inside the scroll view instead, because pinned buttons of that size would cover half the screen.
struct ActionPage<Content: View, Actions: View>: View {
    /// Takes the page out of the accessibility tree, for a page that another screen covers.
    var isHidden = false
    @ViewBuilder let content: Content
    @ViewBuilder let actions: Actions
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                content
                if dynamicTypeSize.isAccessibilitySize { actionStack }
            }
            .frame(maxWidth: 560, alignment: .leading)
            .frame(maxWidth: .infinity)
            .padding(24)
        }
        .accessibilityHidden(isHidden)
        .safeAreaInset(edge: .bottom) {
            if !dynamicTypeSize.isAccessibilitySize {
                actionStack
                    .frame(maxWidth: 560)
                    .padding(.horizontal, 24)
                    .padding(.vertical, 12)
                    .frame(maxWidth: .infinity)
                    .background(.bar)
                    .accessibilityHidden(isHidden)
            }
        }
    }

    private var actionStack: some View { VStack(spacing: 12) { actions } }
}
