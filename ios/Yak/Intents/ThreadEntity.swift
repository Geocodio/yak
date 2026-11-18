import AppIntents
import Foundation

/// A thread or a task as Shortcuts and Siri carry it. Its text is finished: a private thread reads "A private thread".
struct ThreadEntity: AppEntity {
    let id: String
    let title: String
    let subtitle: String

    static let typeDisplayRepresentation = TypeDisplayRepresentation(
        name: LocalizedStringResource("intent.thread.type"))
    static let defaultQuery = ThreadQuery()

    var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "\(title)", subtitle: "\(subtitle)")
    }

    init(_ value: ThreadEntityValue) {
        id = value.id
        title = value.title
        subtitle = value.subtitle
    }
}

/// Entities come from the saved snapshots and the last opened thread; it never uses the network.
struct ThreadQuery: EntityQuery {
    func entities(for identifiers: [String]) async throws -> [ThreadEntity] {
        guard let services = IntentServices.resolve() else { return [] }
        let runner = IntentRunner(services: services)
        var found: [ThreadEntity] = []
        for identifier in identifiers.prefix(50) {
            if let value = await runner.entity(for: identifier) { found.append(ThreadEntity(value)) }
        }
        return found
    }

    func suggestedEntities() async throws -> [ThreadEntity] {
        guard let services = IntentServices.resolve() else { return [] }
        return await IntentRunner(services: services).savedRows().map(ThreadEntity.init)
    }
}
