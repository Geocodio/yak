import AVFoundation

#if canImport(os)
    import os
#endif

/// The audio session, held only while a walkthrough video plays.
@MainActor
protocol AudioSessionControl: AnyObject {
    /// Category `.playback`, mode `.moviePlayback`, then active.
    func activatePlayback() throws
    /// Inactive, telling other apps they may resume. A failure is logged by type.
    func deactivate()
}

@MainActor
final class SystemAudioSession: AudioSessionControl {
    func activatePlayback() throws {
        let session = AVAudioSession.sharedInstance()
        try session.setCategory(.playback, mode: .moviePlayback)
        try session.setActive(true)
    }

    func deactivate() {
        do {
            try AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
        } catch {
            #if canImport(os)
                Logger(subsystem: "Yak", category: "Video")
                    .error("Audio session did not stop: \(String(describing: type(of: error)), privacy: .public)")
            #endif
        }
    }
}
