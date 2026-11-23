import SwiftUI

/// The keys over the keyboard in the editor. Each inserts its text at the cursor; tab inserts the file's own
/// indentation.
struct CodeKeyRow: View {
    let insert: (String) -> Void
    let indentation: () -> String

    private static let symbols = ["{", "}", "(", ")", "$", "=>", ";", "'", "\"", "[", "]", "<", ">", "/"]

    var body: some View {
        ViewThatFits(in: .horizontal) {
            row
            ScrollView(.horizontal) { row }.scrollIndicators(.hidden)
        }
        .frame(maxWidth: .infinity).background(Color.yakCard)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("files.editor.keys")
    }

    private var row: some View {
        HStack(spacing: 0) {
            Button {
                insert(indentation())
            } label: {
                Image(systemName: "arrow.right.to.line").frame(minWidth: 44, minHeight: 44).contentShape(.rect)
            }
            .accessibilityLabel("files.editor.key.tab").accessibilityIdentifier("files.editor.key.tab")
            ForEach(Self.symbols, id: \.self) { symbol in
                Button {
                    insert(symbol)
                } label: {
                    Text(verbatim: symbol).font(.yak(.body, .medium, isMonospaced: true))
                        .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
                }
                .accessibilityIdentifier("files.editor.key.\(symbol)")
            }
        }
        .buttonStyle(.plain).foregroundStyle(Color.primary).dynamicTypeSize(..<DynamicTypeSize.accessibility1)
    }
}
