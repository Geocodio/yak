import SwiftUI

/// A line of status under the header, inset from the page edges: a quiet fill, a dot, and text in which the first
/// sentence is the point and the rest says more. It is neutral on purpose; a saved copy is not an error.
struct InsetStrip<Accessory: View>: View {
    let text: String
    @ViewBuilder var accessory: Accessory

    @ScaledMetric(relativeTo: .footnote) private var dotTop = 6.0

    var body: some View {
        HStack(alignment: .top, spacing: Design.Space.compact) {
            Circle().fill(Color.yakMuted).frame(width: 7, height: 7).padding(.top, dotTop).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: Design.Space.tight) {
                sentences.fixedSize(horizontal: false, vertical: true)
                accessory
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.compact)
        .background(Color.yakBubble, in: .rect(cornerRadius: Design.Radius.chip))
        .padding(.horizontal, Design.Space.medium).padding(.top, Design.Space.compact)
        .accessibilityElement(children: .contain)
    }

    /// The first sentence in the text color and semibold weight, the rest in the quiet color.
    private var sentences: Text {
        guard let split = text.range(of: ". ") else {
            return Text(verbatim: text).font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakText)
        }
        var first = AttributedString(String(text[..<split.lowerBound]) + ".")
        first.font = .yak(.footnote, .semibold)
        first.foregroundColor = .yakText
        var rest = AttributedString(" " + String(text[split.upperBound...]))
        rest.font = .yak(.footnote)
        rest.foregroundColor = .yakMuted
        return Text(first + rest)
    }
}

extension InsetStrip where Accessory == EmptyView {
    init(text: String) { self.init(text: text) { EmptyView() } }
}
