import SwiftUI
import YakKit

/// Two levels: each family's latest version, with older versions under a chevron.
struct ModelPicker: View {
    let composer: ComposerModel
    @Environment(\.dismiss) private var dismiss
    @State private var expanded: Set<String> = []
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var selected: String? {
        let value = composer.effectiveModel
        return composer.modelFamilies.first { $0.family == value }?.latest.value ?? value
    }

    var body: some View {
        NavigationStack {
            Group {
                if composer.modelFamilies.isEmpty {
                    ContentUnavailableView {
                        Label("model.title", systemImage: "cpu")
                    } description: {
                        Text("model.empty")
                    }
                } else {
                    list
                }
            }
            .navigationTitle("model.title").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("model.close") { dismiss() }.accessibilityIdentifier("model.close")
                }
            }
        }
        .presentationDetents(dynamicTypeSize.isAccessibilitySize ? [.large] : [.medium, .large])
        .accessibilityIdentifier("model.picker")
    }

    private var list: some View {
        List {
            Section {
                ForEach(ModelChoices.rows(families: composer.modelFamilies, selected: selected, expanded: expanded)) {
                    row in
                    ModelRow(row: row, isExpanded: expanded.contains(familyName(of: row))) {
                        withAnimation(.smooth(duration: 0.25)) { toggle(familyName(of: row)) }
                    } pick: {
                        composer.selectModel(row.id)
                        dismiss()
                    }
                }
            }
            Section {
                Text("model.footer").font(.footnote).foregroundStyle(.primary.opacity(0.85))
                    .listRowBackground(Color.clear)
                    .listRowInsets(EdgeInsets(top: 0, leading: 4, bottom: 0, trailing: 4))
            }
        }
        .listSectionSpacing(.compact)
    }

    /// The family a row belongs to: a family row by its own id, an older version by the family that lists it.
    private func familyName(of row: ModelChoiceRow) -> String {
        composer.modelFamilies.first { $0.latest.value == row.id || $0.older.contains { $0.value == row.id } }?
            .family ?? row.id
    }

    private func toggle(_ family: String) {
        if expanded.contains(family) { expanded.remove(family) } else { expanded.insert(family) }
    }
}

private struct ModelRow: View {
    let row: ModelChoiceRow
    let isExpanded: Bool
    let toggle: () -> Void
    let pick: () -> Void

    var body: some View {
        HStack(spacing: 8) {
            Button(action: pick) {
                HStack(spacing: 12) {
                    VStack(alignment: .leading, spacing: 2) {
                        title
                        if let detail = row.detail {
                            Text(detail).font(.footnote).foregroundStyle(.primary.opacity(0.75))
                        }
                    }
                    Spacer(minLength: 0)
                    if row.isSelected {
                        Image(systemName: "checkmark").foregroundStyle(.tint).accessibilityLabel("model.selected")
                    }
                }
                .frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityIdentifier("model.row.\(row.id)")
            if case .family(let hasOlder, _) = row.kind, hasOlder {
                Button(action: toggle) {
                    Image(systemName: "chevron.right").rotationEffect(.degrees(isExpanded ? 90 : 0))
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .buttonStyle(.borderless).foregroundStyle(.secondary)
                .accessibilityLabel(Text("model.older \(row.label)"))
                .accessibilityAddTraits(isExpanded ? .isSelected : [])
                .accessibilityIdentifier("model.older.\(row.id)")
            }
        }
        .padding(.leading, row.kind == .pinned ? 16 : 0)
    }

    @ViewBuilder private var title: some View {
        if row.kind == .pinned {
            Text("model.pinned \(row.label)").font(.body)
        } else {
            Text(verbatim: row.label).font(.body.weight(.medium))
        }
    }
}
