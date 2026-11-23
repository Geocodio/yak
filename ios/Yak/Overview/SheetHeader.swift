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
                        cancel.frame(minWidth: 44, minHeight: 44, alignment: .leading).contentShape(.rect)
                        Spacer(minLength: 12)
                        confirm.frame(minWidth: 44, minHeight: 44, alignment: .trailing).contentShape(.rect)
                    }
                }
            } else {
                HStack(spacing: 12) {
                    cancel.frame(minWidth: 44, minHeight: 44, alignment: .leading).contentShape(.rect)
                    titleText.frame(maxWidth: .infinity)
                    confirm.frame(minWidth: 44, minHeight: 44, alignment: .trailing).contentShape(.rect)
                }
            }
        }
        .buttonStyle(.borderless)
        .padding(.horizontal, 16).padding(.top, 16).padding(.bottom, 8)
    }

    private var titleText: some View {
        Text(title).font(.yak(.headline)).multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
            .accessibilityAddTraits(.isHeader)
    }
}

/// A rounded group of rows on the grouped background of an Overview sheet. A scroll view of cards keeps every row in
/// the accessibility tree, which a lazy list does not.
struct SheetCard<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content }
            .padding(.horizontal, 16).padding(.vertical, 4)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.secondarySystemGroupedBackground), in: .rect(cornerRadius: 20))
    }
}
