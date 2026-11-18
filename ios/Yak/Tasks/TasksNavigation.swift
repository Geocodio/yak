import SwiftUI
import YakKit

/// Where the tasks area opens: a list, or one task with its list behind it.
enum TasksRoute: Hashable {
    case tasks
    case observations
    case task(String)
}

/// The Tasks and Observations lists and the task screens, in one navigation stack that replaces the thread screen
/// until the person goes back to the threads.
struct TasksNavigation: View {
    let session: InstanceSession
    let scene: SceneModel
    let instanceURL: URL
    let openWeb: (URL) -> Void
    let close: () -> Void

    @State private var path: [String]
    @State private var tasks: TaskListModel
    @State private var observations: ObservationsModel
    private let root: TasksRoute

    init(
        route: TasksRoute, session: InstanceSession, scene: SceneModel, instanceURL: URL,
        openWeb: @escaping (URL) -> Void, close: @escaping () -> Void
    ) {
        self.session = session
        self.scene = scene
        self.instanceURL = instanceURL
        self.openWeb = openWeb
        self.close = close
        if case .task(let id) = route {
            root = .tasks
            _path = State(initialValue: [id])
        } else {
            root = route
            _path = State(initialValue: [])
        }
        _tasks = State(initialValue: .tasks(session: session))
        _observations = State(initialValue: .observations(session: session))
    }

    var body: some View {
        NavigationStack(path: $path) {
            Group {
                if root == .observations {
                    ObservationsScreen(
                        model: observations, openTask: { path.append($0) }, openWeb: openWeb, close: close)
                } else {
                    TasksScreen(model: tasks, openTask: { path.append($0) }, close: close)
                }
            }
            .navigationDestination(for: String.self) { id in
                TaskDestination(
                    taskID: id, session: session, scene: scene, instanceURL: instanceURL, openWeb: openWeb,
                    back: { path.removeAll() }, didOpenThread: close)
            }
        }
        .onDisappear {
            Task {
                await tasks.stop()
                await observations.stop()
            }
        }
    }
}

/// Owns the model of one pushed task screen.
private struct TaskDestination: View {
    let taskID: String
    let session: InstanceSession
    let scene: SceneModel
    let instanceURL: URL
    let openWeb: (URL) -> Void
    let back: () -> Void
    let didOpenThread: () -> Void
    @State private var model: TaskModel?

    var body: some View {
        Group {
            if let model {
                TaskScreen(
                    model: model, instanceURL: instanceURL, openWeb: openWeb, back: back,
                    didOpenThread: didOpenThread)
            } else {
                Color.yakBackground
            }
        }
        .task(id: taskID) {
            if model == nil { model = TaskModel(taskID: taskID, session: session, scene: scene) }
        }
    }
}
