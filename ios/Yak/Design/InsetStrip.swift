import SwiftUI

/// A line of status under the header, inset from the page edges: a quiet fill, a dot, and text in which the lead is the
/// point and the detail says more. It is neutral on purpose; a saved copy is not an error.
struct InsetStrip<Accessory: View>: View {
    let lead: String
    var detail: String?
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

    /// The lead in the text color and semibold weight, the detail after it in the quiet color.
    private var sentences: Text {
        var first = AttributedString(lead)
        first.font = .yak(.footnote, .semibold)
        first.foregroundColor = .yakText
        guard let detail else { return Text(first) }
        var rest = AttributedString(" " + detail)
        rest.font = .yak(.footnote)
        rest.foregroundColor = .yakMuted
        return Text(first + rest)
    }
}

extension InsetStrip where Accessory == EmptyView {
    init(lead: String, detail: String? = nil) { self.init(lead: lead, detail: detail) { EmptyView() } }
}
