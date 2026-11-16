import SwiftUI
import YakKit

/// App-wide parts, built once per process, by the app and by test mode in the same way. A feature adds one stored
/// property, one line in `make` and, where it follows the instances, one line in `observers`.
@MainActor
final class AppParts {
    let storage: SharedStorage?
    let isTestMode: Bool
    /// Erases an instance's preview web data when it is unpaired or revoked, and sweeps leftovers at launch.
    let previewData: PreviewWebDataObserver
    /// One line per observer; `AppModel` tells each after a pairing, a removal and a revoke.
    private(set) var observers: [any InstanceLifecycleObserver]

    private init(
        storage: SharedStorage?, isTestMode: Bool, previewData: PreviewWebDataObserver,
        observers: [any InstanceLifecycleObserver]
    ) {
        self.storage = storage
        self.isTestMode = isTestMode
        self.previewData = previewData
        self.observers = observers
    }

    /// Opens stores and builds objects only: no network and no disk work beyond that.
    static func make(storage: SharedStorage?, isTestMode: Bool) -> AppParts {
        let previewData = PreviewWebDataObserver(defaults: storage?.defaults ?? UserDefaults.standard)
        return AppParts(
            storage: storage, isTestMode: isTestMode, previewData: previewData, observers: [previewData])
    }

    /// The type of every part this build made, so a test can compare test mode with a release build.
    var partTypeNames: [String] {
        let parts = Mirror(reflecting: self).children.filter { $0.label != "storage" && $0.label != "isTestMode" }
        return parts.compactMap { $0.label }.sorted() + observers.map { String(describing: type(of: $0)) }
    }
}

extension View {
    /// Every scene-root modifier, one line per feature, in one place.
    func appRoot(_ parts: AppParts, model: AppModel) -> some View {
        onOpenURL { url in Task { await model.handle(url: url) } }
            .environment(\.previewDefaults, parts.previewData.defaults)
            .task { await parts.previewData.sweep(instances: model.instances) }
    }
}
