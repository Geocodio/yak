import Observation
import YakKit

/// The privacy-facing system features, in the environment of the window group's root.
@MainActor @Observable
final class SystemFeatures {
    let settings: PrivacySettings
    let appLock: AppLockController
    let faults: DebugFaultSwitch
    @ObservationIgnored let modelBox: ModelBox

    init(settings: PrivacySettings, appLock: AppLockController, faults: DebugFaultSwitch, modelBox: ModelBox) {
        self.settings = settings
        self.appLock = appLock
        self.faults = faults
        self.modelBox = modelBox
    }
}
