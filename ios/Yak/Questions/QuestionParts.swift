import SwiftUI
import YakKit

/// The pieces the task screen's and the thread's question cards share, so both look the same.

extension View {
    /// The card's own surface: a soft tint on the whole card, with room around its content.
    func questionCardSurface() -> some View {
        padding(16).frame(maxWidth: .infinity, alignment: .leading)
            .background(Color.primary.opacity(0.06), in: .rect(cornerRadius: 16))
    }

    /// The surface of an option, a review row and a text field inside the card.
    func questionFieldSurface() -> some View {
        background(Color(.systemBackground), in: .rect(cornerRadius: 12))
    }
}

/// One option of a question: a check mark, the label and its description. Both the label and the description are
/// the server's text.
struct QuestionOptionButton: View {
    let label: String
    let detail: String?
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(alignment: .firstTextBaseline, spacing: 10) {
                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle").accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: label).font(.body).multilineTextAlignment(.leading)
                    if let detail, !detail.isEmpty {
                        Text(verbatim: detail).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                            .multilineTextAlignment(.leading)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(12).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .questionFieldSurface()
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }
}

/// One row of the review: a question's header and the answer, which opens the question again.
struct QuestionReviewRow: View {
    let header: String
    let summary: String
    var error: String?
    let action: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Button(action: action) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: header).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    Text(verbatim: summary).font(.body).multilineTextAlignment(.leading)
                }
                .padding(12).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                .questionFieldSurface()
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .combine)
            .accessibilityHint(Text("question.review.edit"))
            if let error {
                Text(verbatim: error).font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }
}
