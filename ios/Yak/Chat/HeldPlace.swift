import Foundation

/// The row put back after a page of earlier messages, and what it waits for. The hold exists only to keep the place
/// while the documents of the page's text rows arrive, since the rendered text can be taller than the plain text
/// shown before; it ends with the last of them, not with a clock.
struct HeldPlace: Equatable {
    var id: String
    var fraction: CGFloat
    /// The rows the page added that have Markdown text, and so get a document.
    var awaitedIDs: Set<String>

    enum Status: Equatable {
        /// Documents are still missing: apply the place and keep holding.
        case holding
        /// Every document is here: apply the place one last time, then clear the hold.
        case complete
        /// The anchor row has left the list: clear the hold without applying.
        case anchorGone
    }

    func status(hasDocument: (String) -> Bool, hasRow: (String) -> Bool) -> Status {
        if !hasRow(id) { return .anchorGone }
        return awaitedIDs.allSatisfy(hasDocument) ? .complete : .holding
    }
}
