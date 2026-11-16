import SwiftUI

/// Draws a document as one lazy stack of rows, so the cost on the main actor follows what is on screen and not
/// how long the document is. A lazy stack inside another lazy stack builds all of its content at once, so a
/// chat that holds its messages in a lazy stack puts `MarkdownRowList` straight into it instead.
struct MarkdownRowsView: View {
    let rows: [MarkdownRow]
    var initialPage = 0

    var body: some View {
        LazyVStack(alignment: .leading, spacing: 0) { MarkdownRowList(rows: rows, initialPage: initialPage) }
            .frame(maxWidth: .infinity, alignment: .leading)
            .textSelection(.enabled)
            .environment(
                \.openURL,
                OpenURLAction { PlainMarkdownRenderer.opensLink($0) ? .systemAction : .discarded })
    }
}

/// The rows alone, for a lazy stack the caller owns.
struct MarkdownRowList: View {
    let rows: [MarkdownRow]
    /// Only one page of the document is on screen at a time, so what a lazy stack keeps once built is one page
    /// however far the reader goes. A page break row ends each page.
    @State private var page: Int
    @State private var listIdentifier = UUID()
    @State private var scrollRequest: ScrollRequest?

    init(rows: [MarkdownRow], initialPage: Int = 0) {
        self.rows = rows
        _page = State(initialValue: initialPage)
    }

    private struct RowIdentifier: Hashable {
        let list: UUID
        let row: Int
    }

    /// Where the view scrolls after the reader moved to another page. `sequence` makes two moves to the same row
    /// two requests.
    private struct ScrollRequest: Equatable {
        let target: MarkdownPager.ScrollTarget
        let sequence: Int
    }

    var body: some View {
        let pager = MarkdownPager(rows: rows, page: page)
        let range = pager.range
        // Rows are not inside the reader, so the lazy stack keeps building only what is on screen.
        ScrollViewReader { proxy in
            Color.clear.frame(height: 0)
                .onChange(of: scrollRequest) { _, request in
                    guard let target = request?.target else { return }
                    proxy.scrollTo(
                        RowIdentifier(list: listIdentifier, row: target.row), anchor: target.isTop ? .top : .bottom)
                }
        }
        if pager.hasPrevious {
            Button("markdown.previous") { move(pager.showingPrevious) }
                .font(.footnote)
                .padding(.bottom, 12)
                .accessibilityLabel("markdown.previous.label")
                .accessibilityIdentifier("markdown-previous")
        }
        ForEach(range, id: \.self) { index in
            MarkdownRowView(
                row: rows[index],
                barsBelow: index + 1 < range.upperBound
                    ? min(rows[index].decoration.quoteDepth, rows[index + 1].decoration.quoteDepth) : 0
            )
            .id(RowIdentifier(list: listIdentifier, row: index))
        }
        if pager.hasNext {
            Button("markdown.more") { move(pager.showingNext) }
                .font(.footnote)
                .padding(.bottom, 12)
                .accessibilityLabel("markdown.more.label")
                .accessibilityIdentifier("markdown-more")
        }
    }

    private func move(_ result: (pager: MarkdownPager, target: MarkdownPager.ScrollTarget)?) {
        guard let result else { return }
        page = result.pager.current
        scrollRequest = ScrollRequest(target: result.target, sequence: (scrollRequest?.sequence ?? 0) + 1)
        AccessibilityNotification.Announcement(
            String(
                format: String(localized: "markdown.page.announcement"), result.pager.current + 1,
                result.pager.pages.count)
        ).post()
    }
}

private struct MarkdownRowView: View {
    let row: MarkdownRow
    /// How many quote bars continue into the row below.
    let barsBelow: Int

    private static let barStep: CGFloat = 13
    private static let indentStep: CGFloat = 20

    private var spacing: CGFloat {
        switch row.decoration.spacing {
        case .block: 12
        case .item: 6
        case .piece:
            if case .text = row.kind, row.decoration.markerColumns == 0 { 0 } else { 4 }
        }
    }

    var body: some View {
        let depth = row.decoration.quoteDepth
        content
            .padding(.leading, CGFloat(row.decoration.indent) * Self.indentStep + CGFloat(depth) * Self.barStep)
            .padding(.bottom, barsBelow > 0 ? spacing : 0)
            .background(alignment: .leading) {
                HStack(spacing: 10) {
                    ForEach(0..<depth, id: \.self) { level in
                        Rectangle().fill(Color(.systemGray3)).frame(width: 3)
                            .padding(.bottom, level < barsBelow ? 0 : (barsBelow > 0 ? spacing : 0))
                    }
                }
            }
            .padding(.bottom, barsBelow > 0 ? 0 : spacing)
    }

    @ViewBuilder private var content: some View {
        switch row.kind {
        case .text(let text): TextRow(text: text, decoration: row.decoration)
        case .code(let text): CodeBlock(text: text)
        case .table(let header, let rows, let columnWidths):
            TableBlock(header: header, rows: rows, columnWidths: columnWidths)
        case .rule: Divider()
        case .notice: Text("markdown.unformatted.notice").font(.footnote).foregroundStyle(Color.yakMuted)
        case .pageBreak: EmptyView()
        }
    }
}

/// Text with its heading style and list marker. The marker column is as wide as the widest marker of the list,
/// so the text of every row lines up.
private struct TextRow: View {
    let text: AttributedString
    let decoration: MarkdownRow.Decoration
    @ScaledMetric(relativeTo: .body) private var markerColumnWidth = 10.0

    var body: some View {
        if decoration.markerColumns > 0 {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(verbatim: marker)
                    .monospacedDigit()
                    .foregroundStyle(Color.yakMuted)
                    .frame(width: CGFloat(decoration.markerColumns) * markerColumnWidth, alignment: .trailing)
                    .accessibilityHidden(true)
                body(of: text)
            }
            .modifier(SpokenTaskState(label: spokenState, text: text))
        } else {
            body(of: text)
        }
    }

    @ViewBuilder private func body(of text: AttributedString) -> some View {
        if decoration.isUnformatted {
            Text(text).font(.system(.footnote, design: .monospaced))
        } else if decoration.headingLevel > 0 {
            Text(text)
                .font(Self.headingFont(decoration.headingLevel))
                .fontWeight(.semibold)
                .fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
        } else {
            Text(text).fixedSize(horizontal: false, vertical: true)
        }
    }

    private var marker: String {
        switch decoration.marker {
        case .none: ""
        case .bullet: "\u{2022}"
        case .number(let number): "\(number)."
        case .task(let isChecked): isChecked ? "\u{2611}" : "\u{2610}"
        }
    }

    private var spokenState: String {
        switch decoration.marker {
        case .task(true): String(localized: "markdown.task.done")
        case .task(false): String(localized: "markdown.task.notDone")
        default: ""
        }
    }

    private static func headingFont(_ level: Int) -> Font {
        switch level {
        case 1: .title
        case 2: .title2
        case 3: .title3
        case 4: .headline
        default: .subheadline
        }
    }
}

/// Code that scrolls sideways inside its own box.
private struct CodeBlock: View {
    let text: String

    var body: some View {
        ScrollView(.horizontal, showsIndicators: true) {
            Text(verbatim: text)
                .font(.system(.footnote, design: .monospaced))
                .fixedSize()
                .padding(12)
        }
        .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 10))
    }
}

/// A table that scrolls sideways inside its own box; longer cells wrap. The header shows on the first piece of a
/// table that is cut into several.
private struct TableBlock: View {
    let header: [AttributedString]
    let rows: [[AttributedString]]
    let columnWidths: [Int]
    @ScaledMetric(relativeTo: .body) private var characterWidth = 9.0
    @ScaledMetric(relativeTo: .body) private var maximumColumnWidth = 260.0

    private func width(_ column: Int) -> CGFloat {
        guard column < columnWidths.count else { return 0 }
        return min(maximumColumnWidth, max(CGFloat(columnWidths[column]) * characterWidth, characterWidth))
    }

    var body: some View {
        ScrollView(.horizontal, showsIndicators: true) {
            Grid(alignment: .topLeading, horizontalSpacing: 16, verticalSpacing: 8) {
                if !header.isEmpty {
                    GridRow {
                        ForEach(Array(header.enumerated()), id: \.offset) { column, cell in
                            TableCell(text: cell, width: width(column)).fontWeight(.semibold)
                        }
                    }
                    Divider()
                }
                ForEach(Array(rows.enumerated()), id: \.offset) { _, row in
                    GridRow {
                        ForEach(Array(row.enumerated()), id: \.offset) { column, cell in
                            TableCell(text: cell, width: width(column))
                        }
                    }
                }
            }
            .padding(12)
        }
        .background(Color(.secondarySystemFill), in: .rect(cornerRadius: 10))
    }
}

private struct TableCell: View {
    let text: AttributedString
    let width: CGFloat

    var body: some View {
        Text(text)
            .frame(width: width, alignment: .leading)
            .fixedSize(horizontal: false, vertical: true)
    }
}

/// A task row reads its state before its text; other rows read as they are.
private struct SpokenTaskState: ViewModifier {
    let label: String
    let text: AttributedString

    func body(content: Content) -> some View {
        if label.isEmpty {
            content
        } else {
            content.accessibilityElement(children: .ignore).accessibilityLabel(label + String(text.characters))
        }
    }
}
