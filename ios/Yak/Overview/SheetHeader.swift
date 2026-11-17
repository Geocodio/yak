import SwiftUI

/// The top of an Overview sheet: Cancel, the title and the confirming button as ordinary views, so every one of them
/// grows with the text size and wraps. A navigation bar caps the size of its text and clips it.
struct SheetHeader<Cancel: View, Confirm: View>: View {
    let title: LocalizedStringKey
    @ViewBuilder let cancel: Cancel
    @ViewBuilder let confirm: Confirm

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 8) {
                    titleText
                    HStack {
                        cancel
                        Spacer(minLength: 12)
                        confirm
                    }
                }
            } else {
                HStack(spacing: 12) {
                    cancel
                    titleText.frame(maxWidth: .infinity)
                    confirm
                }
            }
        }
        .buttonStyle(.borderless)
        .padding(.horizontal, 16).padding(.top, 16).padding(.bottom, 8)
    }

    private var titleText: some View {
        Text(title).font(.headline).multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
            .accessibilityAddTraits(.isHeader)
    }
}
