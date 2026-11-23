import SwiftUI
import YakKit

/// The Observations list, in place of the thread screen until the person goes back to the threads.
struct ObservationsNavigation: View {
    let session: InstanceSession
    let openTask: (String) -> Void
    let openWeb: (URL) -> Void
    let close: () -> Void

    @State private var observations: ObservationsModel

    init(
        session: InstanceSession, openTask: @escaping (String) -> Void, openWeb: @escaping (URL) -> Void,
        close: @escaping () -> Void
    ) {
        self.session = session
        self.openTask = openTask
        self.openWeb = openWeb
        self.close = close
        _observations = State(initialValue: .observations(session: session))
    }

    var body: some View {
        NavigationStack {
            ObservationsScreen(model: observations, openTask: openTask, openWeb: openWeb, close: close)
        }
        .onDisappear { Task { await observations.stop() } }
    }
}
