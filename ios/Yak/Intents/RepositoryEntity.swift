import AppIntents
import Foundation
import YakKit

/// One repository of an instance, as the "Start a thread" picker lists it.
struct RepositoryEntity: AppEntity {
    /// "owner/name".
    let id: String
    let name: String

    static let typeDisplayRepresentation = TypeDisplayRepresentation(
        name: LocalizedStringResource("intent.repository.type"))
    static let defaultQuery = RepositoryQuery()

    var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "\(name)", subtitle: "\(id)")
    }
}

/// The picker lists the repositories of the Yak chosen in the same intent. The list is loaded within 8 seconds.
struct RepositoryQuery: EntityQuery {
    @IntentParameterDependency<StartThreadIntent>(\.$instance)
    var startThread

    /// The Yak chosen in the same intent, or the most recently used one.
    private func targetInstance(_ services: IntentServicesValue) async -> UUID? {
        if let chosen = startThread?.instance.id { return chosen }
        return await services.instances.all().first?.id
    }

    func entities(for identifiers: [String]) async throws -> [RepositoryEntity] {
        identifiers.prefix(50).map { RepositoryEntity(id: $0, name: $0) }
    }

    func suggestedEntities() async throws -> [RepositoryEntity] {
        guard let services = IntentServices.resolve() else { return [] }
        guard let target = await targetInstance(services) else { return [] }
        do {
            let listed = try await services.submission.repositories(instance: target, budget: IntentRunner.listBudget)
            return listed.repositories.map { RepositoryEntity(id: $0.slug, name: $0.name) }
        } catch {
            return []
        }
    }

    func defaultResult() async -> RepositoryEntity? {
        guard let services = IntentServices.resolve() else { return nil }
        guard let target = await targetInstance(services),
            let listed = try? await services.submission.repositories(instance: target, budget: IntentRunner.listBudget),
            let slug = listed.defaultSlug ?? services.lastRepository(target),
            let match = listed.repositories.first(where: { $0.slug == slug })
        else { return nil }
        return RepositoryEntity(id: match.slug, name: match.name)
    }
}

enum ThreadModeEntity: String, AppEnum {
    case fix, research

    static let typeDisplayRepresentation = TypeDisplayRepresentation(name: LocalizedStringResource("intent.mode.type"))
    static let caseDisplayRepresentations: [ThreadModeEntity: DisplayRepresentation] = [
        .fix: DisplayRepresentation(title: LocalizedStringResource("intent.mode.fix")),
        .research: DisplayRepresentation(title: LocalizedStringResource("intent.mode.research")),
    ]
}
