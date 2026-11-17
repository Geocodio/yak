import Observation
import YakKit

/// The privacy-facing system features, in the environment of the window group's root.
@MainActor @Observable
final class SystemFeatures {
    let settings: PrivacySettings
    let faults: DebugFaultSwitch
    @ObservationIgnored let modelBox: ModelBox

    init(settings: PrivacySettings, faults: DebugFaultSwitch, modelBox: ModelBox) {
        self.settings = settings
        self.faults = faults
        self.modelBox = modelBox
    }
}
