import SwiftUI

/// The packages the app links and their license texts.
struct LicensesScreen: View {
    var body: some View {
        List(LicenseList.entries) { entry in
            NavigationLink {
                LicenseTextScreen(entry: entry)
            } label: {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: entry.name).font(.yak(.body))
                    Text(verbatim: entry.licenseName).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                }
                .frame(minHeight: 44, alignment: .leading)
            }
            .accessibilityIdentifier("licenses.\(entry.id)")
        }
        .navigationTitle("licenses.title")
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("licenses.screen")
    }
}

private struct LicenseTextScreen: View {
    let entry: LicenseEntry
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        ScrollView {
            if let text = LicenseList.text(for: entry, bundle: .main) {
                VStack(alignment: .leading, spacing: 12) {
                    ForEach(Array(paragraphs(text).enumerated()), id: \.offset) { _, paragraph in
                        Text(verbatim: paragraph).font(.yak(.footnote, isMonospaced: true)).textSelection(.enabled)
                            .fixedSize(horizontal: false, vertical: true)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .id(dynamicTypeSize)
                    }
                }
                .padding(16)
            } else {
                Text("licenses.missing").foregroundStyle(Color.yakMuted).padding(16)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .navigationTitle(Text(verbatim: entry.name))
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("license.text")
    }

    private func paragraphs(_ text: String) -> [String] {
        text.components(separatedBy: "\n\n").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
    }
}
