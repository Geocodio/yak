import SwiftUI
import YakKit

/// The Filter sheet: the sources and the starters of the selected tab, each with how many threads it covers, as on
/// the web. A choice narrows the list at once; Done closes the sheet.
struct DrawerFilterSheet: View {
    let drawer: DrawerModel
    let close: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(spacing: 0) {
            titleBar
            ScrollView {
                VStack(alignment: .leading, spacing: Design.Space.large) {
                    let sources = drawer.sourceChoices()
                    let starters = drawer.starterChoices()
                    if sources.isEmpty, starters.isEmpty {
                        Text("drawer.filterSheet.none").font(.yak(.subheadline)).foregroundStyle(Color.yakMuted)
                    }
                    if !sources.isEmpty {
                        group("drawer.filterSheet.source", choices: sources) { key in await drawer.toggleSource(key) }
                    }
                    if !starters.isEmpty {
                        group("drawer.filterSheet.startedBy", choices: starters) { key in
                            await drawer.toggleStarter(key)
                        }
                    }
                }
                .padding(.horizontal, Design.Space.pageInset).padding(.bottom, Design.Space.large)
            }
        }
        .background(Color.yakBar)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("drawer.filterSheet")
    }

    private var titleBar: some View {
        let title = Text("drawer.filterSheet.title").font(.yak(.headline, .semibold))
            .accessibilityAddTraits(.isHeader)
        let clear = Button {
            Task { await drawer.clearFilters() }
        } label: {
            Text("drawer.filterSheet.clear").font(.yak(.body, .medium)).foregroundStyle(Color.yakAction)
                .padding(.horizontal, Design.Space.compact)
                .frame(minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("drawer.filterSheet.clear")
        .accessibilityAddTraits(.isButton)
        .accessibilityIdentifier("drawer.filterSheet.clearAll")
        let done = Button(action: close) {
            Text("common.done").font(.yak(.body, .semibold)).foregroundStyle(Color.yakAction)
                .padding(.horizontal, Design.Space.compact)
                .frame(minHeight: Design.Size.control).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("common.done")
        .accessibilityAddTraits(.isButton)
        .accessibilityIdentifier("drawer.filterSheet.done")
        // The title and the two buttons share a row; at the largest text sizes they stack.
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 0))
            : AnyLayout(HStackLayout(spacing: Design.Space.compact))
        return layout {
            title
            if !dynamicTypeSize.isAccessibilitySize { Spacer(minLength: Design.Space.compact) }
            HStack(spacing: Design.Space.compact) {
                // Clear shows only while a filter is on, so no dimmed button is left.
                if drawer.selection.activeCount > 0 { clear }
                done
            }
        }
        .padding(.leading, Design.Space.pageInset).padding(.trailing, Design.Space.compact)
    }

    private func group(
        _ title: LocalizedStringKey, choices: [FilterChoice], toggle: @escaping (String) async -> Void
    ) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(title).font(.yakGroupHeading).foregroundStyle(Color.yakMuted)
                .padding(.bottom, Design.Space.tight).accessibilityAddTraits(.isHeader)
            ForEach(choices) { choice in
                Button {
                    Task { await toggle(choice.key) }
                } label: {
                    row(choice)
                }
                .buttonStyle(.plain)
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(accessibilityLabel(choice))
                .accessibilityAddTraits(choice.isPicked ? [.isButton, .isSelected] : .isButton)
                .accessibilityIdentifier("drawer.filterSheet.\(choice.key)")
                Divider().overlay(Color.yakLine)
            }
        }
    }

    private func row(_ choice: FilterChoice) -> some View {
        HStack(spacing: Design.Space.medium) {
            Image(systemName: choice.isPicked ? "checkmark.square.fill" : "square")
                .font(.yak(.title3)).foregroundStyle(choice.isPicked ? Color.yakAction : Color.yakMuted)
                .accessibilityHidden(true)
            Text(title(of: choice)).font(.yak(.body)).foregroundStyle(Color.yakText)
                .multilineTextAlignment(.leading)
            Spacer(minLength: Design.Space.compact)
            if drawer.tabs.supportsFacets {
                Text(choice.count, format: .number).font(.yak(.footnote)).foregroundStyle(Color.yakText)
            }
        }
        .frame(minHeight: Design.Size.control).contentShape(.rect)
    }

    private func title(of choice: FilterChoice) -> String {
        switch choice.role {
        case .viewer: String(localized: "drawer.filterSheet.you")
        case .yak: String(localized: "drawer.filterSheet.yak")
        case .person: choice.label
        }
    }

    private func accessibilityLabel(_ choice: FilterChoice) -> String {
        guard drawer.tabs.supportsFacets else { return title(of: choice) }
        return "\(title(of: choice)), \(String(localized: "drawer.filterSheet.threads \(choice.count)"))"
    }
}
