import SwiftUI
import UIKit

/// Version, licenses and, in Debug and TestFlight builds, the Debug menu.
struct SettingsAboutSection: View {
    @Environment(SystemFeatures.self) private var system

    private var version: String {
        let info = Bundle.main.infoDictionary
        return PrivacyRows.versionText(
            short: info?["CFBundleShortVersionString"] as? String, build: info?["CFBundleVersion"] as? String)
    }

    var body: some View {
        Section {
            Text(verbatim: version).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                .contextMenu { Button("about.version.copy") { UIPasteboard.general.string = version } }
                .accessibilityIdentifier("settings.version")
            NavigationLink {
                LicensesScreen()
            } label: {
                Text("about.licenses").frame(minHeight: 44, alignment: .leading)
            }
            .accessibilityIdentifier("settings.licenses")
            if PrivacyRows.showsDebugRow(kind: system.debug.kind) {
                NavigationLink {
                    DebugMenuScreen(model: system.debug)
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("about.debug")
                        Text(verbatim: PrivacyRows.debugSubtitle(fault: system.debug.fault))
                            .font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                    }
                    .frame(minHeight: 44, alignment: .leading)
                }
                .accessibilityIdentifier("settings.debug")
            }
        }
        .task { await system.debug.start() }
    }
}
