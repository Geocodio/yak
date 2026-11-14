import SwiftUI

/// Named color tokens from the asset catalog. The rust accent is only for the primary button.
extension Color {
    static let yakRust = Color("Rust")
    static let yakOnRust = Color("OnRust")
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
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(.primary)
            .frame(maxWidth: .infinity, minHeight: 44)
            .padding(.vertical, 6)
            .background(.quaternary.opacity(configuration.isPressed ? 0.6 : 1), in: .rect(cornerRadius: 14))
    }
}
