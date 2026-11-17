import Foundation
import SwiftUI
import YakKit

/// What either widget shows, in one shape: the needs-you and running content types carry the same fields, and the
/// views differ only in their header and the empty sentence. Built from a content type, never from a snapshot.
struct WidgetDisplay: Equatable, Sendable {
    enum Kind: Sendable { case needsYou, running }

    let kind: Kind
    let state: WidgetState
    let instanceName: String
    let countLabel: String
    let count: Int
    let rows: [WidgetRowContent]
    let link: URL?
    let updatedAt: Date?
    let isStale: Bool

    init(_ content: NeedsYouWidgetContent) {
        kind = .needsYou
        state = content.state
        instanceName = content.instanceName
        countLabel = content.countLabel
        count = content.count
        rows = content.rows
        link = content.link
        updatedAt = content.updatedAt
        isStale = content.isStale
    }

    init(_ content: RunningWidgetContent) {
        kind = .running
        state = content.state
        instanceName = content.instanceName
        countLabel = content.countLabel
        count = content.count
        rows = content.rows
        link = content.link
        updatedAt = content.updatedAt
        isStale = content.isStale
    }

    var headerColorIsSuccess: Bool { kind == .running }

    /// The line under a row's title. A running row without a step says "Working"; a needs-you row without a label
    /// says its reason's words. The last line of a stale widget says how old the data is.
    func detail(of row: WidgetRowContent, isLast: Bool, now: Date) -> Text {
        if isStale, isLast, let updatedAt {
            return Text(WidgetStrings.updated(String(localized: WidgetStrings.age(from: updatedAt, to: now))))
        }
        if let detail = row.detail, !detail.isEmpty { return Text(verbatim: detail) }
        return Text(kind == .running ? WidgetStrings.working : WidgetStrings.reason(row.reason))
    }
}
