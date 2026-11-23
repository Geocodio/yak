import SwiftUI
import YakKit

/// The two lists the Tasks screen used to carry that have no thread: pull request reviews and setup runs. They
/// live on the web, so each row opens its page in the in-app browser. Setup runs sit on the Runs tab of each
/// repository, so the row opens the repository list, the best address that exists for all of them.
struct SettingsWebSection: View {
    let instanceURL: URL
    @State private var browserLink: BrowserLink?

    var body: some View {
        Section {
            row("settings.web.reviews", path: "pr-reviews", identifier: "settings.web.reviews")
            row("settings.web.setup", path: "repos", identifier: "settings.web.setup")
        } header: {
            Text("settings.web.header")
        }
        .sheet(item: $browserLink) { BrowserSheet(url: $0.url).ignoresSafeArea() }
    }

    @ViewBuilder private func row(_ title: LocalizedStringKey, path: String, identifier: String) -> some View {
        if let url = WebLinkRule.safeURL(instanceURL.appending(path: path).absoluteString) {
            Button {
                browserLink = BrowserLink(url: url)
            } label: {
                HStack {
                    Text(title)
                    Spacer(minLength: 8)
                    Image(systemName: "arrow.up.right").font(.footnote.weight(.semibold))
                        .foregroundStyle(Color.yakMuted).accessibilityHidden(true)
                }
                .frame(minHeight: 44, alignment: .leading)
            }
            .foregroundStyle(.primary)
            .accessibilityIdentifier(identifier)
        }
    }
}
