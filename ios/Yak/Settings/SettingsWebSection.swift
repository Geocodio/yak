import SwiftUI
import YakKit

/// The two lists the Tasks screen used to carry that have no thread: pull request reviews and setup runs. They
/// live on the web, so each row opens its page.
struct SettingsWebSection: View {
    let instanceURL: URL

    var body: some View {
        Section {
            row("settings.web.reviews", path: "pr-reviews", identifier: "settings.web.reviews")
            row("settings.web.setup", path: "repos", identifier: "settings.web.setup")
        } header: {
            Text("settings.web.header")
        }
    }

    private func row(_ title: LocalizedStringKey, path: String, identifier: String) -> some View {
        Link(destination: instanceURL.appending(path: path)) {
            HStack {
                Text(title)
                Spacer(minLength: 8)
                Image(systemName: "arrow.up.right").font(.footnote.weight(.semibold)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
            }
            .frame(minHeight: 44, alignment: .leading)
        }
        .accessibilityIdentifier(identifier)
    }
}
