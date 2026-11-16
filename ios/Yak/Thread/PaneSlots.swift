import Observation
import SwiftUI
import YakKit

private struct PaneIDKey: EnvironmentKey { static let defaultValue: PaneID? = nil }
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

/// A view a pane hands to the bar or the menu. It is built and read on the main actor only. Two values differ whenever the pane built a new one, so the
/// receiver sees every change.
struct PaneSlotContent: Equatable, @unchecked Sendable {
    private let token = UUID()
    let view: AnyView
    static func == (first: PaneSlotContent, second: PaneSlotContent) -> Bool { first.token == second.token }
}

struct PaneBarRowKey: PreferenceKey {
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
    /// The pane's own row in the bottom bar, shown while this pane is focused.
    func paneBarRow<Row: View>(@ViewBuilder _ row: () -> Row) -> some View {
        modifier(PaneSlotModifier(slot: .barRow, content: AnyView(row())))
    }

    /// Rows the thread menu shows above its own while this pane is focused.
    func paneMenuItems<Items: View>(@ViewBuilder _ items: () -> Items) -> some View {
        modifier(PaneSlotModifier(slot: .menuItems, content: AnyView(items())))
    }
}

private struct PaneSlotModifier: ViewModifier {
    enum Slot { case barRow, menuItems }
    let slot: Slot
    let content: AnyView
    @Environment(\.paneID) private var paneID

    func body(content base: Content) -> some View {
        let value = paneID.map { [$0: PaneSlotContent(view: content)] } ?? [:]
        switch slot {
        case .barRow: base.preference(key: PaneBarRowKey.self, value: value)
        case .menuItems: base.preference(key: PaneMenuItemsKey.self, value: value)
        }
    }
}

/// What the panes of one thread screen handed up, read by the bar and the menu. Only those two read it, so a
/// change redraws them and not the screen that owns this object.
@MainActor @Observable
final class PaneSlots {
    private(set) var barRows: [PaneID: PaneSlotContent] = [:]
    private(set) var menuItems: [PaneID: PaneSlotContent] = [:]

    func setBarRows(_ rows: [PaneID: PaneSlotContent]) { barRows = rows }
    func setMenuItems(_ items: [PaneID: PaneSlotContent]) { menuItems = items }
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
            .onPreferenceChange(PaneMenuItemsKey.self) { slots.setMenuItems($0) }
    }
}
