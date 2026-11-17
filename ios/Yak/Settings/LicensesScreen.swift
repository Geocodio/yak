import SwiftUI

/// The packages the app links and their license texts.
struct LicensesScreen: View {
    var body: some View {
        List(LicenseList.entries) { entry in
            NavigationLink {
                LicenseTextScreen(entry: entry)
            } label: {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: entry.name).font(.body)
                    Text(verbatim: entry.licenseName).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
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

    var body: some View {
        ScrollView {
            if let text = LicenseList.text(for: entry, bundle: .main) {
                LazyVStack(alignment: .leading, spacing: 12) {
                    ForEach(Array(paragraphs(text).enumerated()), id: \.offset) { _, paragraph in
                        Text(verbatim: paragraph).font(.system(.footnote, design: .monospaced)).textSelection(.enabled)
                            .frame(maxWidth: .infinity, alignment: .leading)
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
