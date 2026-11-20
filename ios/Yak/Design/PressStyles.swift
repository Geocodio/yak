import SwiftUI

/// What a pressed control does: it dims and, unless Reduce Motion is on, sinks a little. The change is quick, so a tap
/// answers at once.
struct PressFeedback: ViewModifier {
    let isPressed: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func body(content: Content) -> some View {
        content
            .scaleEffect(isPressed && !reduceMotion ? Motion.pressedScale : 1)
            .opacity(isPressed ? Motion.pressedOpacity : 1)
            .animation(Motion.resolve(Motion.quick, reduceMotion: reduceMotion), value: isPressed)
    }
}

extension View {
    func pressFeedback(_ isPressed: Bool) -> some View { modifier(PressFeedback(isPressed: isPressed)) }
}

/// The plain button with a designed press: for tappable text, icons, cards and bar buttons.
struct PressableButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        Label(configuration: configuration)
    }

    /// A separate view, because a style reads `isEnabled` only from inside its label.
    private struct Label: View {
        let configuration: Configuration
        @Environment(\.isEnabled) private var isEnabled

        var body: some View {
            configuration.label
                .opacity(isEnabled ? 1 : Design.disabledOpacity)
                .pressFeedback(configuration.isPressed)
        }
    }
}

/// A list row's button: the row takes the pressed fill while a finger is on it, and a lighter fill while it is the
/// open one.
struct RowButtonStyle: ButtonStyle {
    var isSelected = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .background(fill(isPressed: configuration.isPressed), in: .rect(cornerRadius: Design.Radius.row))
            .animation(Motion.resolve(Motion.quick, reduceMotion: reduceMotion), value: configuration.isPressed)
    }

    private func fill(isPressed: Bool) -> Color {
        if isPressed { return Color.yakFillStrong }
        return isSelected ? Color.yakFill : Color.clear
    }
}

extension ButtonStyle where Self == PressableButtonStyle {
    static var pressable: PressableButtonStyle { PressableButtonStyle() }
}
