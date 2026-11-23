import SwiftUI
import YakKit

/// One row of a parsed patch. Lines wrap with a hanging indent, so a sideways swipe always pages the files.
struct DiffRowView: View {
    let row: DiffRow
    @Environment(\.horizontalSizeClass) private var sizeClass

    var body: some View {
        switch row.kind {
        case .hunk(let header):
            Text(verbatim: header).font(.yak(.footnote, .medium, isMonospaced: true)).foregroundStyle(Color.yakMuted)
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12).padding(.vertical, 6)
                .background(Color.primary.opacity(0.05))
                .accessibilityLabel(Text("files.row.hunk \(header)"))
        case .noNewlineAtEnd:
            Text("files.row.noNewline").font(.yak(.caption, .medium)).foregroundStyle(Color.yakMuted)
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12).padding(.vertical, 2)
        case .context, .added, .removed:
            line
        }
    }

    private var line: some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Text(verbatim: row.newLine.map(String.init) ?? "").font(.yak(.caption, .medium))
                .foregroundStyle(Color.yakMuted).frame(width: 36, alignment: .trailing)
            Text(verbatim: marker).font(.yak(.footnote, .semibold, isMonospaced: true))
            Text(verbatim: row.text).font(
                .yak(.footnote, sizeClass == .regular ? .medium : .regular, isMonospaced: true)
            )
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 8).padding(.vertical, 2)
        .background(tint)
        .accessibilityElement(children: .combine).accessibilityLabel(spoken)
    }

    private var marker: String {
        switch row.kind {
        case .added: "+"
        case .removed: "\u{2212}"
        default: " "
        }
    }

    private var tint: Color {
        switch row.kind {
        case .added: Color.green.opacity(0.16)
        case .removed: Color.orange.opacity(0.16)
        default: Color.clear
        }
    }

    private var spoken: String {
        switch row.kind {
        case .added: String(localized: "files.row.added \(row.text)")
        case .removed: String(localized: "files.row.removed \(row.text)")
        default: row.text
        }
    }
}

extension Color {
    /// Readable on the page in light and dark: the system green and orange pulled toward the text color.
    static let diffAdded = Color.green.mix(with: .primary, by: 0.4)
    static let diffRemoved = Color.orange.mix(with: .primary, by: 0.4)
}
