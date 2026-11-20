import SwiftUI

/// A short sheet opens at half height and can be pulled to full height; at accessibility text sizes it opens full.
private struct ShortSheetDetents: ViewModifier {
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    func body(content: Content) -> some View {
        content.presentationDetents(dynamicTypeSize.isAccessibilitySize ? [.large] : [.medium, .large])
    }
}

extension View {
    func shortSheetDetents() -> some View { modifier(ShortSheetDetents()) }
}
