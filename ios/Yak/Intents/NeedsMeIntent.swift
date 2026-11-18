import AppIntents
import Foundation

/// Speaks how many things wait for the person, with counts and instance names only, never a title.
struct NeedsMeIntent: AppIntent {
    static let title = LocalizedStringResource("intent.needsMe.title")
    static let authenticationPolicy: IntentAuthenticationPolicy = .requiresAuthentication

    @Parameter(title: LocalizedStringResource("intent.param.instance"))
    var instance: InstanceEntity?

    func perform() async throws -> some IntentResult & ReturnsValue<[ThreadEntity]> & ProvidesDialog {
        guard let services = IntentServices.resolve() else {
            return .result(value: [], dialog: IntentDialog(LocalizedStringResource("intent.dialog.noInstance")))
        }
        let answer = await IntentRunner(services: services).needsMe(instance: instance?.id)
        return .result(
            value: answer.rows.map(ThreadEntity.init),
            dialog: IntentDialog(LocalizedStringResource(stringLiteral: answer.dialog)))
    }
}
