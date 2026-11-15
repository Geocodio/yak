import SwiftUI
import YakKit

/// The pane button of the bar and of the trailing strip. A tap opens the menu of panes; press, slide and release
/// picks one in a single motion, which is how the system's menu works. The menu carries the identifier `pane.menu`
/// and the label "Thread panes"; with more than one pane the menu shows that heading too.
struct PaneMenuButton: View {
    let panes: [PaneID]
    @Binding var focused: PaneID

    var body: some View {
        Menu {
            if panes.count > 1 {
                Section {
                    picker
                } header: {
                    Text("pane.menu")
                }
            } else {
                picker
            }
        } label: {
            HStack(spacing: 4) {
                Image(systemName: PaneCatalog.descriptor(for: focused)?.symbol ?? "bubble.left")
                Image(systemName: "chevron.up.chevron.down").font(.caption2.weight(.semibold))
            }
            .font(.body)
            .foregroundStyle(.primary)
            .padding(.horizontal, 6)
            .frame(minWidth: 44, minHeight: 44)
            .background(Color.primary.opacity(0.08), in: .capsule)
            .contentShape(.capsule)
        }
        .tint(.primary)
        .menuOrder(.fixed)
        .accessibilityLabel(Text("pane.menu"))
        .accessibilityValue(Text(PaneCatalog.descriptor(for: focused)?.name ?? ""))
        .accessibilityIdentifier("pane.menu")
    }

    private var picker: some View {
        Picker(selection: $focused) {
            ForEach(panes, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    Label(descriptor.name, systemImage: descriptor.symbol).tag(id)
                        .accessibilityIdentifier("pane.menu.\(id.rawValue)")
                }
            }
        } label: {
            Text("pane.menu")
        }
        .pickerStyle(.inline)
    }
}

/// The panes as a row of icon-over-name buttons, the selected one with a neutral fill.
struct PaneRow: View {
    let panes: [PaneID]
    @Binding var focused: PaneID
    let thread: ThreadModel

    var body: some View {
        ViewThatFits(in: .horizontal) {
            row
            ScrollView(.horizontal, showsIndicators: false) { row }
        }
    }

    private var row: some View {
        HStack(spacing: 4) {
            ForEach(panes, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    PaneRowButton(
                        descriptor: descriptor, isSelected: id == focused,
                        hasBadge: descriptor.hasBadge(thread)
                    ) { focused = id }
                }
            }
        }
    }
}

private struct PaneRowButton: View {
    let descriptor: PaneDescriptor
    let isSelected: Bool
    let hasBadge: Bool
    let select: () -> Void

    var body: some View {
        Button(action: select) {
            VStack(spacing: 2) {
                Image(systemName: descriptor.symbol).font(.body)
                    .overlay(alignment: .topTrailing) { if hasBadge { QuestionBadge().offset(x: 6, y: -3) } }
                Text(descriptor.name).font(.caption2).lineLimit(1).minimumScaleFactor(0.7)
            }
            .foregroundStyle(.primary)
            .padding(.horizontal, 12).padding(.vertical, 4)
            .frame(minWidth: 56, minHeight: 44)
            .background(isSelected ? Color.primary.opacity(0.12) : .clear, in: .rect(cornerRadius: 12))
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(Text(descriptor.name))
        .accessibilityValue(hasBadge ? Text("pane.badge.question") : Text(""))
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier("pane.row.\(descriptor.id.rawValue)")
    }
}

/// The dot on Chat while a question is open. A system color, never the rust accent.
struct QuestionBadge: View {
    var body: some View {
        Circle().fill(Color.blue).frame(width: 9, height: 9)
            .overlay(Circle().stroke(Color(.systemBackground), lineWidth: 1.5))
            .accessibilityHidden(true)
    }
}

/// The panes as bottom-bar toolbar items for the system's vertical strip. Only the placement that gets a strip
/// declares them, so the system's pill never covers the app's own bar.
@available(iOS 27.1, *)
struct PaneStripToolbar: ToolbarContent {
    let panes: [PaneID]
    @Binding var focused: PaneID
    let thread: ThreadModel

    var body: some ToolbarContent {
        ToolbarItemGroup(placement: .bottomBar) {
            ForEach(panes, id: \.self) { id in
                if let descriptor = PaneCatalog.descriptor(for: id) {
                    Button {
                        focused = id
                    } label: {
                        VStack(spacing: 2) {
                            Image(systemName: descriptor.symbol)
                                .overlay(alignment: .topTrailing) {
                                    if descriptor.hasBadge(thread) { QuestionBadge().offset(x: 6, y: -3) }
                                }
                            // Fit: caption2 on one line passes the contrast audit and leaves room for a fifth caption ("Overview")
                            // when five panes share the strip; a longer name must shorten, not scale.
                            Text(descriptor.name).font(.caption2).foregroundStyle(.primary).lineLimit(1)
                        }
                    }
                    .accessibilityLabel(Text(descriptor.name))
                    .accessibilityValue(descriptor.hasBadge(thread) ? Text("pane.badge.question") : Text(""))
                    .accessibilityAddTraits(id == focused ? .isSelected : [])
                    .accessibilityIdentifier("pane.strip.\(id.rawValue)")
                }
            }
        }
        .axisBehavior(.verticalPreferred)
    }
}

/// Whether the system offers a vertical toolbar strip here. It reads `toolbarVerticalEdge`, which exists from
/// iOS 27.1, and gives its content the answer.
struct ToolbarStripReader<Content: View>: View {
    @ViewBuilder let content: (Bool) -> Content

    var body: some View {
        if #available(iOS 27.1, *) {
            Probe(content: content)
        } else {
            content(false)
        }
    }

    @available(iOS 27.1, *)
    private struct Probe: View {
        let content: (Bool) -> Content
        @Environment(\.toolbarVerticalEdge) private var edge

        var body: some View { content(edge != nil) }
    }
}
