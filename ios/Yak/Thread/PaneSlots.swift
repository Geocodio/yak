import Observation
import SwiftUI
import YakKit

private struct PaneIDKey: EnvironmentKey { static let defaultValue: PaneID? = nil }
private struct OpenAddSubThreadKey: EnvironmentKey { static let defaultValue: (@MainActor @Sendable () -> Void)? = nil }

extension EnvironmentValues {
    /// Opens the Add a sub-thread sheet the thread screen presents; nil outside a thread screen.
    var openAddSubThread: (@MainActor @Sendable () -> Void)? {
        get { self[OpenAddSubThreadKey.self] }
        set { self[OpenAddSubThreadKey.self] = newValue }
    }
}

private struct IsPaneShownKey: EnvironmentKey { static let defaultValue = true }

extension EnvironmentValues {
    /// The pane a view belongs to; set by `PaneHost` around each pane.
    var paneID: PaneID? {
        get { self[PaneIDKey.self] }
        set { self[PaneIDKey.self] = newValue }
    }

    /// Whether the pane around this view is on screen. A pane that pauses work, polls or shows a chip only while
    /// visible reads this or `SceneModel.isShown(_:)`, never the focused pane directly.
    var isPaneShown: Bool {
        get { self[IsPaneShownKey.self] }
        set { self[IsPaneShownKey.self] = newValue }
    }
}

/// A view a pane hands to the bar or the menu. It is built and read on the main actor only. Two values are equal
/// when the pane gave the same `key`, so a pane that renders again with unchanged content does not redraw the bar or
/// the menu. The key holds whatever the row shows (a count, a title); a row that reads an observable by itself needs
/// only a constant key.
struct PaneSlotContent: Equatable, @unchecked Sendable {
    let key: AnyHashable
    let view: AnyView
    static func == (first: PaneSlotContent, second: PaneSlotContent) -> Bool { first.key == second.key }
}

struct PaneBarRowKey: PreferenceKey {
    static let defaultValue: [PaneID: PaneSlotContent] = [:]
    static func reduce(value: inout [PaneID: PaneSlotContent], nextValue: () -> [PaneID: PaneSlotContent]) {
        value.merge(nextValue()) { _, next in next }
    }
}

struct PaneStripRowKey: PreferenceKey {
    static let defaultValue: [PaneID: PaneSlotContent] = [:]
    static func reduce(value: inout [PaneID: PaneSlotContent], nextValue: () -> [PaneID: PaneSlotContent]) {
        value.merge(nextValue()) { _, next in next }
    }
}

struct PaneMenuItemsKey: PreferenceKey {
    static let defaultValue: [PaneID: PaneSlotContent] = [:]
    static func reduce(value: inout [PaneID: PaneSlotContent], nextValue: () -> [PaneID: PaneSlotContent]) {
        value.merge(nextValue()) { _, next in next }
    }
}

extension View {
    /// The pane's own row in the bottom bar, shown while this pane is focused. `key` changes whenever the row's
    /// content does; the bar redraws only then.
    func paneBarRow<Row: View>(key: some Hashable, @ViewBuilder _ row: () -> Row) -> some View {
        modifier(PaneSlotModifier(slot: .barRow, content: PaneSlotContent(key: key, view: AnyView(row()))))
    }

    /// The pane's controls in the trailing strip of a short window (a phone in landscape), top to bottom. While a
    /// pane has them the bottom bar leaves out the pane's row. `key` works as for `paneBarRow`.
    func paneStripRow<Row: View>(key: some Hashable, @ViewBuilder _ row: () -> Row) -> some View {
        modifier(PaneSlotModifier(slot: .stripRow, content: PaneSlotContent(key: key, view: AnyView(row()))))
    }

    /// Rows the thread menu shows above its own while this pane is focused. `key` works as for `paneBarRow`.
    func paneMenuItems<Items: View>(key: some Hashable, @ViewBuilder _ items: () -> Items) -> some View {
        modifier(PaneSlotModifier(slot: .menuItems, content: PaneSlotContent(key: key, view: AnyView(items()))))
    }
}

private struct PaneSlotModifier: ViewModifier {
    enum Slot { case barRow, stripRow, menuItems }
    let slot: Slot
    let content: PaneSlotContent
    @Environment(\.paneID) private var paneID

    func body(content base: Content) -> some View {
        let value = paneID.map { [$0: content] } ?? [:]
        switch slot {
        case .barRow: base.preference(key: PaneBarRowKey.self, value: value)
        case .stripRow: base.preference(key: PaneStripRowKey.self, value: value)
        case .menuItems: base.preference(key: PaneMenuItemsKey.self, value: value)
        }
    }
}

/// What the panes of one thread screen handed up, read by the bar and the menu. Only those two read it, so a
/// change redraws them and not the screen that owns this object.
@MainActor @Observable
final class PaneSlots {
    private(set) var barRows: [PaneID: PaneSlotContent] = [:]
    private(set) var stripRows: [PaneID: PaneSlotContent] = [:]
    private(set) var menuItems: [PaneID: PaneSlotContent] = [:]

    func setBarRows(_ rows: [PaneID: PaneSlotContent]) { barRows = rows }
    func setStripRows(_ rows: [PaneID: PaneSlotContent]) { stripRows = rows }
    func setMenuItems(_ items: [PaneID: PaneSlotContent]) { menuItems = items }
}

/// The focused pane's controls in the trailing strip.
struct PaneStripRow: View {
    let slots: PaneSlots
    let focused: PaneID

    var body: some View {
        if let row = slots.stripRows[focused] { row.view }
    }
}

/// The focused pane's menu rows, above the thread menu's own.
struct PaneMenuItems: View {
    let slots: PaneSlots
    let focused: PaneID

    var body: some View {
        if let items = slots.menuItems[focused] { items.view }
    }
}

extension View {
    /// Collects the panes' bar rows and menu items into `slots`.
    func collectingPaneSlots(into slots: PaneSlots) -> some View {
        onPreferenceChange(PaneBarRowKey.self) { slots.setBarRows($0) }
            .onPreferenceChange(PaneStripRowKey.self) { slots.setStripRows($0) }
            .onPreferenceChange(PaneMenuItemsKey.self) { slots.setMenuItems($0) }
    }
}
