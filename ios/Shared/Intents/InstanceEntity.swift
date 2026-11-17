import AppIntents
import Foundation
import YakKit

/// One paired Yak, as a widget's configuration and the intents choose it.
struct InstanceEntity: AppEntity {
    let id: UUID
    /// Bounded to 60 characters.
    let name: String
    let host: String
    /// The paired address and the server's own address; the Focus filter allows the hosts of both.
    let address: URL
    let canonicalAddress: URL?

    static let typeDisplayRepresentation = TypeDisplayRepresentation(
        name: LocalizedStringResource("widget.entity.type", table: "Widgets"))
    static let defaultQuery = InstanceQuery()

    var displayRepresentation: DisplayRepresentation {
        DisplayRepresentation(title: "\(name)", subtitle: "\(host)")
    }

    init(_ instance: Instance) {
        id = instance.id
        name = WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit)
        host = Self.host(of: instance)
        address = instance.url
        canonicalAddress = instance.canonicalURL
    }

    /// The instance as the Focus filter's rule reads it.
    var instance: Instance {
        Instance(
            id: id, url: address, name: name, userName: "", lastUsedAt: .distantPast, canonicalURL: canonicalAddress)
    }

    static func host(of instance: Instance) -> String { instance.url.host() ?? instance.url.absoluteString }
}

struct InstanceQuery: EntityQuery, EntityStringQuery {
    func entities(for identifiers: [UUID]) async throws -> [InstanceEntity] {
        await readyInstances().filter { identifiers.contains($0.id) }
    }

    func suggestedEntities() async throws -> [InstanceEntity] { await readyInstances() }

    func entities(matching string: String) async throws -> [InstanceEntity] {
        let needle = string.lowercased()
        return await readyInstances().filter {
            $0.name.lowercased().contains(needle) || $0.host.lowercased().contains(needle)
        }
    }

    func defaultResult() async -> InstanceEntity? { await readyInstances().first }

    /// Paired instances, most recently used first. Nothing is shown when the shared storage cannot be opened.
    private func readyInstances() async -> [InstanceEntity] {
        guard let store = SharedStorage.open()?.instanceStore() else { return [] }
        var ready: [InstanceEntity] = []
        for instance in await store.all() where await store.state(of: instance.id) != .needsPairing {
            ready.append(InstanceEntity(instance))
        }
        return ready
    }
}
