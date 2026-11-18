import AppIntents
import Foundation

/// Opens a thread or a task through its `yak://` link, so the app's link path picks the instance and the screen.
struct OpenThreadIntent: AppIntent {
    static let title = LocalizedStringResource("intent.openThread.title")
    static let authenticationPolicy: IntentAuthenticationPolicy = .requiresAuthentication

    @Parameter(title: LocalizedStringResource("intent.param.thread"))
    var target: ThreadEntity

    func perform() async throws -> some IntentResult & OpensIntent {
        guard let services = IntentServices.resolve(),
            let value = await IntentRunner(services: services).entity(for: target.id)
        else { throw IntentMessage(message: String(localized: "intent.dialog.noInstance")) }
        return .result(opensIntent: OpenURLIntent(value.link))
    }
}
