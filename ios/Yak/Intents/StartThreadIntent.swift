import AppIntents
import Foundation

/// Starts a thread from Siri, Shortcuts or the Action button. It never opens Yak; the thread it started is returned, so
/// a shortcut can pass it to "Open thread". The item is written to disk before any request, so a thread is neither lost
/// nor started twice.
struct StartThreadIntent: AppIntent {
    static let title = LocalizedStringResource("intent.start.title")
    static let description = IntentDescription(LocalizedStringResource("intent.start.description"))
    static let authenticationPolicy: IntentAuthenticationPolicy = .requiresAuthentication

    @Parameter(title: LocalizedStringResource("intent.param.instance"))
    var instance: InstanceEntity?

    @Parameter(title: LocalizedStringResource("intent.param.repository"))
    var repository: RepositoryEntity?

    @Parameter(title: LocalizedStringResource("intent.param.mode"), default: .fix)
    var mode: ThreadModeEntity

    @Parameter(
        title: LocalizedStringResource("intent.param.text"),
        inputOptions: String.IntentInputOptions(multiline: true))
    var text: String

    func perform() async throws -> some IntentResult & ProvidesDialog & ReturnsValue<ThreadEntity?> {
        guard let services = IntentServices.resolve() else {
            return .result(value: nil, dialog: IntentDialog(LocalizedStringResource("intent.dialog.noInstance")))
        }
        let answer = await IntentRunner(services: services).startThread(
            instance: instance?.id, repository: repository?.id, mode: mode.rawValue, text: text)
        return .result(
            value: answer.thread.map(ThreadEntity.init),
            dialog: IntentDialog(LocalizedStringResource(stringLiteral: answer.dialog)))
    }
}
