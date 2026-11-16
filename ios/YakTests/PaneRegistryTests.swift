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

    @Test func aViewOutsideAPaneHostCountsAsShown() {
        #expect(EnvironmentValues().isPaneShown)
        #expect(EnvironmentValues().paneID == nil)
    }
}
