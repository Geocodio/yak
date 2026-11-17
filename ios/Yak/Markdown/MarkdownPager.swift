/// Which page of a document the reader sees, and where the view scrolls when the reader moves. A page break row
/// ends each page; the rows in between are the page.
struct MarkdownPager: Equatable {
    /// The row to bring into view after a move: the first row of the next page at the top of the screen, or the
    /// last row of the previous page at the bottom, so the reader keeps their place.
    struct ScrollTarget: Equatable {
        let row: Int
        let isTop: Bool
    }

    let pages: [Range<Int>]
    let current: Int

    init(rows: [MarkdownRow], page: Int = 0) {
        var pages: [Range<Int>] = []
        var start = 0
        for (index, row) in rows.enumerated() {
            if case .pageBreak = row.kind {
                pages.append(start..<index)
                start = index + 1
            }
        }
        pages.append(start..<rows.count)
        self.pages = pages
        current = min(max(page, 0), pages.count - 1)
    }

    private init(pages: [Range<Int>], current: Int) {
        self.pages = pages
        self.current = current
    }

    var range: Range<Int> { pages[current] }
    var hasPrevious: Bool { current > 0 }
    var hasNext: Bool { current + 1 < pages.count }

    /// The pager after "Show more", and where to scroll: the top of the new page.
    var showingNext: (pager: MarkdownPager, target: ScrollTarget)? {
        guard hasNext else { return nil }
        let next = MarkdownPager(pages: pages, current: current + 1)
        return (next, ScrollTarget(row: next.range.lowerBound, isTop: true))
    }

    /// The pager after "Show previous", and where to scroll: the end of the page before, where the reader left off.
    var showingPrevious: (pager: MarkdownPager, target: ScrollTarget)? {
        guard hasPrevious else { return nil }
        let previous = MarkdownPager(pages: pages, current: current - 1)
        return (
            previous, ScrollTarget(row: max(previous.range.upperBound - 1, previous.range.lowerBound), isTop: false)
        )
    }
}
