import AppIntents
import Foundation

/// Opens the thread the person opened last on an instance. With none it says so and opens nothing.
struct OpenLastThreadIntent: AppIntent {
    static let title = LocalizedStringResource("intent.openLast.title")
    static let authenticationPolicy: IntentAuthenticationPolicy = .requiresAuthentication

    @Parameter(title: LocalizedStringResource("intent.param.instance"))
    var instance: InstanceEntity?

    func perform() async throws -> some IntentResult & ProvidesDialog & OpensIntent {
        guard let services = IntentServices.resolve() else {
            throw IntentMessage(message: String(localized: "intent.dialog.noInstance"))
        }
        let answer = await IntentRunner(services: services).lastThread(instance: instance?.id)
        guard let link = answer.link else { throw IntentMessage(message: answer.dialog) }
        return .result(
            opensIntent: OpenURLIntent(link),
            dialog: IntentDialog(LocalizedStringResource(stringLiteral: answer.dialog)))
    }
}
