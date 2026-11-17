import Testing

@testable import YakKit

@Test func onlyBuiltAndAllowedPanesAppear() {
    let all = ThreadPanes(chat: true, preview: true, terminal: true, changes: true, files: true)
    #expect(Panes.available(server: all) == [.chat, .preview, .files, .overview])
    #expect(FilesMode.built.contains(.changed))
    let some = ThreadPanes(chat: true, preview: false, terminal: false, changes: true, files: false)
    #expect(Panes.available(server: some, built: PaneID.allCases) == [.chat, .files, .overview])
    #expect(Panes.available(server: all, built: PaneID.allCases) == PaneID.allCases)
    #expect(Panes.available(server: nil, built: PaneID.allCases) == [.chat])
}

@Test(arguments: [
    (false, false, false, PaneControlPlacement.barRow), (true, false, false, .menuButton),
    (true, true, false, .trailingStrip), (false, true, false, .trailingStrip),
    (true, false, true, .systemStrip), (true, true, true, .systemStrip), (false, false, true, .systemStrip),
    (false, true, true, .systemStrip),
])
func placementFollowsSizeClassesAndTheStrip(
    compactWidth: Bool, compactHeight: Bool, edge: Bool, expected: PaneControlPlacement
) {
    let placement = PaneControlPlacement.choose(
        isCompactWidth: compactWidth, isCompactHeight: compactHeight, hasVerticalToolbarEdge: edge)
    #expect(placement == expected)
    #expect(placement.declaresBottomToolbarItems == (expected == .systemStrip))
    #expect(placement.showsPaneButtonInBar == (expected == .menuButton))
    #expect(placement.showsPaneRowInBar == (expected == .barRow))
    #expect(placement.hidesTitle == (expected == .trailingStrip))
}

@Test func neighborsStopAtTheEnds() {
    #expect(Panes.neighbor(of: .chat, in: [.chat, .files, .overview], step: 1) == .files)
    #expect(Panes.neighbor(of: .chat, in: [.chat, .files, .overview], step: -1) == nil)
    #expect(Panes.neighbor(of: .overview, in: [.chat, .files, .overview], step: 1) == nil)
    #expect(Panes.neighbor(of: .chat, in: [.chat], step: 1) == nil)
    #expect(Panes.neighbor(of: .terminal, in: [.chat], step: 1) == nil)
}

@Test func filesIsBuiltOnlyWithAMode() {
    #expect(Panes.built.contains(.files) == !FilesMode.built.isEmpty)
    #expect(Panes.built.contains(.chat))
    #expect(Panes.built.contains(.overview))
}
