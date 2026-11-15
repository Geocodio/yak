import Foundation
import Testing

@testable import YakKit

struct ShellListTests {
    private func titles(_ list: ShellList) -> [String] { list.shells.map(\.title) }

    @Test func namesFollowTheWeb() {
        var list = ShellList()
        for _ in 0..<4 { _ = list.add(id: UUID(), hasPreviewPort: true) }
        #expect(titles(list) == ["main", "dev server", "shell 2", "shell 3"])
        #expect(!list.canAdd)
        #expect(list.add(id: UUID(), hasPreviewPort: true) == nil)
        #expect(list.shells.count == 4)
    }

    @Test func withoutAPortTheSecondIsShellTwo() {
        var list = ShellList()
        for _ in 0..<2 { _ = list.add(id: UUID(), hasPreviewPort: false) }
        #expect(titles(list) == ["main", "shell 2"])
    }

    @Test func aFreedNumberIsReused() {
        var list = ShellList()
        let ids = (0..<3).map { _ in UUID() }
        for id in ids { _ = list.add(id: id, hasPreviewPort: false) }
        list.remove(ids[1])
        let added = list.add(id: UUID(), hasPreviewPort: false)
        #expect(added?.title == "shell 2")
    }

    @Test func removingTheFocusedMovesToThePrevious() {
        var list = ShellList()
        let ids = (0..<3).map { _ in UUID() }
        for id in ids { _ = list.add(id: id, hasPreviewPort: false) }
        list.remove(ids[2])
        #expect(list.focused == ids[1])
        list.focus(ids[0])
        list.remove(ids[0])
        #expect(list.focused == ids[1])
        list.focus(UUID())
        #expect(list.focused == ids[1])
    }

    @Test func removingTheLastLeavesNoFocus() {
        var list = ShellList()
        let id = UUID()
        _ = list.add(id: id, hasPreviewPort: false)
        list.remove(id)
        #expect(list.focused == nil)
        #expect(list.shells.isEmpty)
        #expect(list.add(id: UUID(), hasPreviewPort: false)?.title == "main")
    }
}
