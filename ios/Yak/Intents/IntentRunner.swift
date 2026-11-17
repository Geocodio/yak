import Foundation
import YakKit

/// What an intent answers: the sentence that is spoken or shown, and the rows or the thread it hands on.
struct IntentAnswer: Equatable, Sendable {
    var dialog: String
    var thread: ThreadEntityValue?
    var rows: [ThreadEntityValue] = []
    var link: URL?
}

/// A thread as an entity carries it: finished text, never a title that was not already cleared for display.
struct ThreadEntityValue: Equatable, Sendable {
    let id: String
    let title: String
    let subtitle: String
    let link: URL

    init(_ row: ThreadEntityRow) {
        id = row.entityID
        title = row.title.spoken
        subtitle = row.instanceName
        link = row.link
    }

    init(id: String, title: String, subtitle: String, link: URL) {
        self.id = id
        self.title = title
        self.subtitle = subtitle
        self.link = link
    }
}

/// The rules of the three intents, over the services, so tests need no intent machinery. The budgets add up to 18
/// seconds: the repository list 8, the send 10.
struct IntentRunner: Sendable {
    static let listBudget: Duration = .seconds(8)
    static let sendBudget: Duration = .seconds(10)
    static let refreshBudget: Duration = .seconds(8)

    let services: IntentServicesValue

    private func text(_ resource: String.LocalizationValue) -> String { String(localized: resource) }

    private func host(_ instance: Instance) -> String { instance.url.host() ?? instance.url.absoluteString }

    private func resolveInstance(_ id: UUID?) async -> Instance? {
        let all = await services.instances.all()
        if let id { return all.first { $0.id == id } }
        return all.first
    }

    // MARK: Start a thread

    func startThread(instance id: UUID?, repository: String?, mode: String, text body: String) async -> IntentAnswer {
        guard let instance = await resolveInstance(id) else { return IntentAnswer(dialog: noInstance) }
        guard await services.instances.state(of: instance.id) == .ready else {
            return IntentAnswer(dialog: pairAgain(instance))
        }
        var slug = repository
        if slug == nil {
            do {
                let listed = try await services.submission.repositories(instance: instance.id, budget: Self.listBudget)
                let known = Set(listed.repositories.map(\.slug))
                let last = services.lastRepository(instance.id)
                slug = [listed.defaultSlug, last].compactMap { $0 }.first { known.contains($0) }
            } catch {
                return IntentAnswer(dialog: repositoryFailure(error, instance: instance))
            }
        }
        guard let slug else { return IntentAnswer(dialog: text("intent.dialog.chooseRepository")) }
        let request = StartThreadRequest(instance: instance.id, repo: slug, mode: mode, text: body)
        switch await services.submission.submit(request, budget: Self.sendBudget) {
        case .started(let threadID):
            let entity = ThreadEntityValue(
                id: "\(instance.id.uuidString)/thread/\(threadID)",
                title: String(localized: "intent.thread.new \(slug)"),
                subtitle: WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit),
                link: Self.link(instance: instance, kind: "thread", id: threadID))
            return IntentAnswer(dialog: String(localized: "intent.dialog.started \(slug)"), thread: entity)
        case .saved:
            return IntentAnswer(dialog: text("intent.dialog.saved"))
        case .refused(let message):
            return IntentAnswer(dialog: message.isEmpty ? text("intent.dialog.refused") : message)
        case .invalid(let problem):
            switch problem {
            case .emptyText: return IntentAnswer(dialog: text("intent.dialog.emptyText"))
            case .textTooLong: return IntentAnswer(dialog: text("intent.dialog.textTooLong"))
            case .unknownRepository: return IntentAnswer(dialog: text("intent.dialog.chooseRepository"))
            case .noInstance: return IntentAnswer(dialog: noInstance)
            }
        case .needsPairing:
            return IntentAnswer(dialog: pairAgain(instance))
        }
    }

    private var noInstance: String { text("intent.dialog.noInstance") }

    private func pairAgain(_ instance: Instance) -> String {
        String(localized: "intent.dialog.pairAgain \(host(instance))")
    }

    private func repositoryFailure(_ failure: StartThreadOptionsFailure, instance: Instance) -> String {
        switch failure {
        case .offline: String(localized: "intent.dialog.offline \(host(instance))")
        case .timedOut: text("intent.dialog.timedOut")
        case .needsPairing: pairAgain(instance)
        case .unavailable: text("intent.dialog.reposUnavailable")
        case .noInstance: noInstance
        }
    }

    // MARK: What needs me

    func needsMe(instance id: UUID?) async -> IntentAnswer {
        let all = await services.instances.all()
        let chosen = id == nil ? all : all.filter { $0.id == id }
        if chosen.isEmpty {
            return IntentAnswer(dialog: id == nil || all.isEmpty ? noInstance : text("intent.dialog.instanceRemoved"))
        }
        let results = await withTaskGroup(of: (Int, WidgetRefresh).self) { group in
            for (index, instance) in chosen.enumerated() {
                group.addTask {
                    (index, await services.refresher.refresh(instance: instance.id, budget: Self.refreshBudget))
                }
            }
            var collected: [(Int, WidgetRefresh)] = []
            for await result in group { collected.append(result) }
            return collected.sorted { $0.0 < $1.0 }.map(\.1)
        }
        let rows = zip(chosen, results).map { (instance: $0, snapshot: $1.snapshot, isFresh: $1.isFresh) }
        let summary = NeedsYouSummary.make(rows)
        var sentences = [spokenCount(summary)]
        for (instance, result) in zip(chosen, results) where !result.isFresh {
            if case .needsPairing = result.state {
                sentences.append(pairAgain(instance))
            } else {
                sentences.append(String(localized: "intent.needsMe.unreachable \(instance.name)"))
            }
        }
        let entities = zip(chosen, results).flatMap { instance, result in
            result.snapshot.map { ThreadEntityRows.needsYou($0, instance: instance).map(ThreadEntityValue.init) } ?? []
        }
        return IntentAnswer(dialog: sentences.joined(separator: " "), rows: entities)
    }

    private func spokenCount(_ summary: NeedsYouSummary) -> String {
        if summary.parts.count == 1, let part = summary.parts.first {
            switch part.count {
            case 0: return String(localized: "intent.needsMe.none \(part.instanceName)")
            case 1: return String(localized: "intent.needsMe.one \(part.instanceName)")
            default: return String(localized: "intent.needsMe.many \(part.count) \(part.instanceName)")
            }
        }
        if summary.total == 0 { return text("intent.needsMe.noneAnywhere") }
        let list = summary.parts.map { String(localized: "intent.needsMe.part \($0.count) \($0.instanceName)") }
            .joined(separator: ", ")
        return summary.total == 1
            ? String(localized: "intent.needsMe.oneAnywhere \(list)")
            : String(localized: "intent.needsMe.manyAnywhere \(summary.total) \(list)")
    }

    // MARK: Open last thread

    func lastThread(instance id: UUID?) async -> IntentAnswer {
        guard let instance = await resolveInstance(id) else { return IntentAnswer(dialog: noInstance) }
        let cache = Cache(instance: instance.id, files: services.environment.files, clock: services.environment.clock)
        guard let row = await LastThread.find(cache: cache, instance: instance) else {
            return IntentAnswer(dialog: String(localized: "intent.dialog.noLastThread \(instance.name)"))
        }
        return IntentAnswer(dialog: text("intent.dialog.opening"), thread: ThreadEntityValue(row), link: row.link)
    }

    // MARK: Entities

    /// The needs-you rows of every paired instance from the saved snapshots, and each instance's last thread. No network.
    func savedRows() async -> [ThreadEntityValue] {
        var values: [ThreadEntityValue] = []
        for instance in await services.instances.all() {
            let cache = Cache(
                instance: instance.id, files: services.environment.files, clock: services.environment.clock)
            if let snapshot = await cache.widgetSnapshot() {
                values += ThreadEntityRows.needsYou(snapshot, instance: instance).map(ThreadEntityValue.init)
            }
            if let last = await LastThread.find(cache: cache, instance: instance) {
                values.append(ThreadEntityValue(last))
            }
        }
        return values
    }

    /// The entity for an id of the form "<instance>/<thread|task>/<id>", from the paired instances.
    func entity(for entityID: String) async -> ThreadEntityValue? {
        guard let parsed = ThreadEntityRows.parse(entityID),
            let instance = await services.instances.all().first(where: { $0.id == parsed.instance })
        else { return nil }
        if let saved = await savedRows().first(where: { $0.id == entityID }) { return saved }
        return ThreadEntityValue(
            id: entityID,
            title: parsed.kind == .task
                ? String(localized: "intent.thread.task") : String(localized: "intent.thread.generic"),
            subtitle: WidgetText.bounded(instance.name, limit: WidgetText.instanceNameLimit),
            link: Self.link(instance: instance, kind: parsed.kind.rawValue, id: parsed.id))
    }

    /// `yak://thread` or `yak://task` with the paired address, built so an id cannot add a query item.
    static func link(instance: Instance, kind: String, id: String) -> URL {
        var components = URLComponents()
        components.scheme = "yak"
        components.host = kind
        components.queryItems = [
            URLQueryItem(name: "instance", value: instance.url.absoluteString), URLQueryItem(name: "id", value: id),
        ]
        return components.url ?? URL(string: "yak://\(kind)")!
    }
}
