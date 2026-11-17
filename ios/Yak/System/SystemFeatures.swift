import Observation
import YakKit

/// The privacy-facing system features, in the environment of the window group's root.
@MainActor @Observable
final class SystemFeatures {
    let settings: PrivacySettings
    let appLock: AppLockController
    let spotlight: SpotlightIndexer
    let debug: DebugMenuModel
    let faults: DebugFaultSwitch
    @ObservationIgnored let modelBox: ModelBox

    init(
        settings: PrivacySettings, appLock: AppLockController, spotlight: SpotlightIndexer, debug: DebugMenuModel,
        faults: DebugFaultSwitch, modelBox: ModelBox
    ) {
        self.settings = settings
        self.appLock = appLock
        self.spotlight = spotlight
        self.debug = debug
        self.faults = faults
        self.modelBox = modelBox
    }

    /// The lock changed what Spotlight may hold, so the index follows.
    func setAppLock(_ isOn: Bool) async {
        await appLock.setOn(isOn)
        await spotlight.reindex()
    }

    func setSpotlightIncludesPrivate(_ isOn: Bool) async {
        settings.spotlightIncludesPrivate = isOn
        await spotlight.reindex()
    }
}
