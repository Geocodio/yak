import SwiftUI
import YakKit

/// A repository or a pull request found by the search: a type icon, the title with the matched text in bold, the
/// subtitle in the mono face, and an arrow when the row opens a page in the browser instead of a thread.
struct SearchResultRowView: View {
    let result: SearchResult
    let highlight: String
    let opensBrowser: Bool
    let open: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        Button(action: open) {
            HStack(alignment: .top, spacing: Design.Space.snug) {
                Image(systemName: icon).font(.yak(.footnote, .semibold)).foregroundStyle(Color.yakMuted)
                    .frame(width: Design.Size.resultIcon).padding(.top, Design.Space.small)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: Design.Space.nudge) {
                    Text(title).multilineTextAlignment(.leading).lineLimit(wrapsLines ? nil : 1)
                    Text(result.subtitle).font(.yakRowRepository).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.leading).lineLimit(wrapsLines ? nil : 1).truncationMode(.middle)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: Design.Space.tight)
                if opensBrowser {
                    Image(systemName: "arrow.up.right").font(.yak(.caption, .semibold)).foregroundStyle(Color.yakMuted)
                        .padding(.top, Design.Space.small).accessibilityHidden(true)
                }
            }
            .padding(.leading, Design.Space.snug).padding(.trailing, Design.Space.medium)
            .padding(.vertical, Design.Space.compact)
            .frame(minHeight: Design.Size.row).contentShape(.rect)
        }
        .buttonStyle(RowButtonStyle(isSelected: false))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(label)
        .accessibilityHint(opensBrowser ? Text("search.result.opensBrowser") : Text(verbatim: ""))
        .accessibilityIdentifier("search.result.\(result.identity)")
    }

    private var wrapsLines: Bool { dynamicTypeSize > .large }

    private var icon: String {
        switch result.kind {
        case .repository: "book.closed"
        case .pullRequest: "arrow.triangle.pull"
        case .thread: "bubble.left"
        }
    }

    private var title: AttributedString {
        var name = AttributedString(result.title)
        name.font = .yakRowTitle
        name.foregroundColor = .yakText
        let options: String.CompareOptions = [.caseInsensitive, .diacriticInsensitive]
        if !highlight.isEmpty, let range = name.range(of: highlight, options: options) {
            name[range].font = .yak(.subheadline, .bold)
        }
        return name
    }

    private var label: String {
        let kind =
            switch result.kind {
            case .repository: String(localized: "search.kind.repository")
            case .pullRequest: String(localized: "search.kind.pullRequest")
            case .thread: String(localized: "search.kind.thread")
            }
        return [kind, result.title, result.subtitle].joined(separator: ". ")
    }
}

/// The heading of a group of results: the name in the quiet color and the count in a pill, read as one heading.
struct SearchGroupHeading: View {
    let title: String
    let count: Int?
    var identifier = "search.heading"

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        HStack(spacing: Design.Space.compact) {
            Text(title).font(.yakGroupHeading).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
            if let count {
                Text(count, format: .number).font(.yak(.caption, .semibold)).foregroundStyle(Color.yakMuted)
                    .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.hairline)
                    .background(Color.yakBubble, in: .capsule)
            }
        }
        // The text size rebuilds the heading, so the audit's size changes are never read from a view that kept the old one.
        .id(dynamicTypeSize)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(count.map { "\(title), \($0)" } ?? title)
        .accessibilityAddTraits(.isHeader)
        .accessibilityIdentifier(identifier)
    }
}

/// A line above the list that says why the results are not the server's: the status dot, a bold lead and the rest.
struct SearchStrip: View {
    enum Tone { case offline, limited }

    let tone: Tone
    let lead: String
    let detail: String
    let identifier: String

    @ScaledMetric(relativeTo: .footnote) private var dotTop = 6.0

    var body: some View {
        HStack(alignment: .top, spacing: Design.Space.compact) {
            Circle().fill(dot).frame(width: 7, height: 7).padding(.top, dotTop).accessibilityHidden(true)
            sentences.fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.compact)
        .background(dot.opacity(Design.noticeTintOpacity), in: .rect(cornerRadius: Design.Radius.chip))
        .padding(.horizontal, Design.Space.medium).padding(.top, Design.Space.compact)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier(identifier)
    }

    private var dot: Color { tone == .offline ? .yakFailure : .yakWarningDot }

    /// The lead in semibold, the detail after it, both in the text color.
    private var sentences: Text {
        var first = AttributedString(lead)
        first.font = .yak(.footnote, .semibold)
        var rest = AttributedString(" " + detail)
        rest.font = .yak(.footnote)
        var whole = first + rest
        whole.foregroundColor = .yakText
        return Text(whole)
    }
}

/// Four rows of shapes where the results will be, so the list does not jump when they arrive.
struct SearchSkeleton: View {
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                SearchGroupHeading(title: String(localized: "search.group.threads"), count: nil)
                    .padding(.leading, Design.Space.rowInset + Design.Space.tight).padding(.top, Design.Space.small)
                    .padding(.bottom, Design.Space.compact)
                VStack(alignment: .leading, spacing: Design.Space.large) {
                    ForEach(0..<Design.Size.searchSkeletonRows, id: \.self) { index in row(index) }
                }
                .padding(.horizontal, Design.Space.rowInset)
                .shimmering()
            }
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("search.loading")
            .accessibilityIdentifier("search.loading")
        }
        .scrollDisabled(true)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
    }

    private func row(_ index: Int) -> some View {
        HStack(alignment: .center, spacing: Design.Space.snug) {
            RoundedRectangle(cornerRadius: Design.Radius.chip).fill(Color.primary.opacity(0.1))
                .frame(width: Design.Space.medium, height: Design.Space.medium)
            RoundedRectangle(cornerRadius: Design.Radius.chip).fill(Color.primary.opacity(0.1))
                .frame(height: Design.Space.large)
                .frame(maxWidth: index % 2 == 0 ? .infinity : Design.Size.previewWidth * 0.6, alignment: .leading)
        }
        .frame(minHeight: Design.Size.row)
    }
}
