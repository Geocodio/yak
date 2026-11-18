#if DEBUG
    import SwiftUI
    import UIKit
    import YakKit

    /// The widget, intent and quick action arguments of test mode: `-yakTestScreen widget-gallery` (read by `YakApp`),
    /// `-yakTestIntent start-thread|needs-me|open-last` and `-yakTestQuickAction new-thread|open`.
    extension TestMode {
        static var intentName: String? { value("yakTestIntent") }
        static var quickActionType: String? { value("yakTestQuickAction") }
    }

    extension TestMode {
        /// `-yakTestQuickAction new-thread|open`: the tap that launched the app, as the system would deliver it.
        @MainActor static func deliverQuickAction(for instance: Instance) {
            guard let type = quickActionType else { return }
            // The stub's plain http address is not one that widgets and quick actions build links for, so the tap is
            // made by hand.
            var components = URLComponents()
            components.scheme = "yak"
            components.host = type == "open" ? "thread" : "new"
            components.queryItems = [URLQueryItem(name: "instance", value: instance.url.absoluteString)]
            guard let link = components.url else { return }
            let item = UIApplicationShortcutItem(
                type: type, localizedTitle: "Test", localizedSubtitle: nil, icon: nil,
                userInfo: ["link": link.absoluteString as NSString])
            QuickActions.deliver(item)
        }
    }

    /// Runs one intent's rules against the stub once the seeded instance exists and shows the sentence it answers in a
    /// label, because a UI test cannot drive Siri or Shortcuts.
    struct IntentTestResultView: View {
        let name: String
        @State private var result = ""

        var body: some View {
            Text(verbatim: result)
                .font(.caption2)
                .accessibilityIdentifier("intent.result")
                .accessibilityLabel(Text(verbatim: result))
                .allowsHitTesting(false)
                .task { result = await run() }
        }

        private func run() async -> String {
            guard let services = IntentServices.resolve() else { return "no services" }
            for _ in 0..<50 where await services.instances.all().isEmpty {
                try? await Task.sleep(for: .milliseconds(200))
            }
            let runner = IntentRunner(services: services)
            switch name {
            case "needs-me": return await runner.needsMe(instance: nil).dialog
            case "open-last": return await runner.lastThread(instance: nil).dialog
            default:
                return await runner.startThread(
                    instance: nil, repository: nil, mode: "fix", text: "Add a retry to the import job"
                ).dialog
            }
        }
    }
#endif
