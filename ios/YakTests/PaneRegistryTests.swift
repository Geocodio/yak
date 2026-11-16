import SwiftUI
import Testing
import YakKit

@testable import Yak

@MainActor
@Suite struct PaneRegistryTests {
    @Test func everyBuiltPaneHasADescriptor() {
        for id in Panes.built {
            #expect(PaneCatalog.descriptor(for: id) != nil, "\(id) has no descriptor")
        }
    }

    @Test func everyDescriptorIsBuiltOrIsFilesWithoutAMode() {
        for descriptor in PaneCatalog.all {
            #expect(Panes.built.contains(descriptor.id) || descriptor.id == .files, "\(descriptor.id) is not built")
        }
    }

    @Test func noPaneIsRegisteredTwice() {
        let ids = PaneCatalog.all.map(\.id)
        #expect(Set(ids).count == ids.count)
    }

    @Test func filesIsBuiltOnlyWithAMode() {
        #expect(Panes.built.contains(.files) == !FilesMode.built.isEmpty)
    }

    @Test func everyBuiltModeHasOneView() {
        for mode in FilesMode.built {
            #expect(FilesModeRegistry.views.filter { $0.mode == mode }.count == 1, "\(mode) needs exactly one view")
        }
        let modes = FilesModeRegistry.views.map { $0.mode }
        #expect(Set(modes).count == modes.count)
    }

    @Test func eachPaneSeesWhetherItIsShown() {
        let panes: [PaneID] = [.chat, .files]
        let scene = SceneModel(state: SceneState())
        #expect(panes.map { scene.isShown($0, among: panes) } == [true, false])
        scene.focus(pane: .files)
        #expect(panes.map { scene.isShown($0, among: panes) } == [false, true])
        scene.focus(pane: .terminal)
        #expect(panes.map { scene.isShown($0, among: panes) } == [true, false], "a pane the thread lacks shows chat")
    }

    @Test func aViewOutsideAPaneHostCountsAsShown() {
        #expect(EnvironmentValues().isPaneShown)
        #expect(EnvironmentValues().paneID == nil)
    }
}

@MainActor
@Suite struct PaneSlotContentTests {
    @Test func contentWithTheSameKeyIsEqual() {
        let first = PaneSlotContent(key: "a", view: AnyView(Text("one")))
        #expect(first == PaneSlotContent(key: "a", view: AnyView(Text("one"))))
        #expect(first != PaneSlotContent(key: "b", view: AnyView(Text("one"))))
    }

    private struct Pane: View {
        let tick: Int
        var body: some View {
            Text("pane \(tick)").paneBarRow(key: "row") { Text("row") }
        }
    }

    private struct Host: View {
        let tick: Int
        let counter: Counter
        var body: some View {
            Pane(tick: tick).environment(\.paneID, .chat)
                .onPreferenceChange(PaneBarRowKey.self) { _ in counter.value += 1 }
        }
    }

    private final class Counter { var value = 0 }

    @Test func aPaneRenderedTwiceWithTheSameContentPushesOneValue() async throws {
        let counter = Counter()
        let controller = UIHostingController(rootView: Host(tick: 0, counter: counter))
        let window = UIWindow(frame: CGRect(x: 0, y: 0, width: 300, height: 300))
        window.rootViewController = controller
        window.makeKeyAndVisible()
        controller.view.layoutIfNeeded()
        let afterFirst = counter.value
        controller.rootView = Host(tick: 1, counter: counter)
        controller.view.layoutIfNeeded()
        try await Task.sleep(for: .milliseconds(100))
        #expect(afterFirst == 1)
        #expect(counter.value == 1)
    }
}
