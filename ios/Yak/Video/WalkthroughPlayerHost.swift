import SwiftUI
import YakKit

extension EnvironmentValues {
    /// The process's one player; nil where a view is built without it (tests, galleries).
    @Entry var walkthroughPlayback: WalkthroughPlayback?
}

extension View {
    /// Shows the full walkthrough player over this scene while the playback asks for it.
    func walkthroughPlayerHost(scene: SceneModel) -> some View {
        modifier(WalkthroughPlayerHost(scene: scene))
    }
}

private struct WalkthroughPlayerHost: ViewModifier {
    let scene: SceneModel
    @Environment(\.walkthroughPlayback) private var playback
    @Environment(\.scenePhase) private var scenePhase

    private var id: ObjectIdentifier { ObjectIdentifier(scene) }

    func body(content: Content) -> some View {
        content
            .sheet(isPresented: isPresented) {
                if let playback { WalkthroughPlayerSheet(playback: playback, scene: scene) }
            }
            .onAppear { playback?.registerHost(scene, isForeground: scenePhase == .active) }
            .onDisappear { playback?.unregisterHost(scene) }
            .onChange(of: scenePhase) { _, phase in
                playback?.registerHost(scene, isForeground: phase == .active)
                if phase == .background { playback?.didEnterBackground() }
                if phase == .active { playback?.didBecomeActive() }
            }
    }

    /// A swipe down or Done ends the video unless it moved to picture in picture, which dismisses the sheet itself.
    private var isPresented: Binding<Bool> {
        Binding(
            get: { playback?.presentedScene == id },
            set: { newValue in
                guard !newValue, let playback, playback.presentedScene == id,
                    !playback.isPictureInPictureActive
                else { return }
                playback.close()
            })
    }
}
