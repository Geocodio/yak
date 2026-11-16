import SwiftUI
import YakKit

/// One row of a parsed patch. Lines wrap with a hanging indent, so a sideways swipe always pages the files.
struct DiffRowView: View {
    let row: DiffRow

    var body: some View {
        switch row.kind {
        case .hunk(let header):
            Text(verbatim: header).font(.footnote.monospaced().weight(.medium)).foregroundStyle(Color.yakMuted)
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12).padding(.vertical, 6)
                .background(Color.primary.opacity(0.05))
                .accessibilityLabel(Text("files.row.hunk \(header)"))
        case .noNewlineAtEnd:
            Text("files.row.noNewline").font(.caption.weight(.medium)).foregroundStyle(Color.yakMuted)
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 12).padding(.vertical, 2)
        case .context, .added, .removed:
            line
        }
    }

    private var line: some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Text(verbatim: row.newLine.map(String.init) ?? "").font(.caption.monospacedDigit().weight(.medium))
                .foregroundStyle(Color.yakMuted).frame(width: 36, alignment: .trailing).accessibilityHidden(true)
            Text(verbatim: marker).font(.footnote.monospaced().weight(.semibold)).accessibilityHidden(true)
            Text(verbatim: row.text).font(.footnote.monospaced()).fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 8).padding(.vertical, 2)
        .background(tint)
        .accessibilityRepresentation { Text(verbatim: spoken) }
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
