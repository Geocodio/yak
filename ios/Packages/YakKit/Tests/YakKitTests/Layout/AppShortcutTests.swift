import Testing

@testable import YakKit

private struct Combination: Hashable {
    let key: ShortcutKey
    let modifiers: ShortcutModifiers
}

private func combination(_ shortcut: AppShortcut) -> Combination {
    Combination(key: shortcut.key, modifiers: shortcut.modifiers)
}

@Suite struct AppShortcutTests {
    @Test func everySpecShortcutIsThere() {
        let command: ShortcutModifiers = .command
        let table: [(ShortcutKey, ShortcutModifiers, AppShortcut)] = [
            (.character("1"), command, .focusChat), (.character("2"), command, .focusPreview),
            (.character("3"), command, .focusFiles), (.character("4"), command, .focusTerminal),
            (.character("5"), command, .focusOverview), (.character("k"), command, .searchThreads),
            (.character("n"), command, .newThread), (.character("n"), [.command, .shift], .newWindow),
            (.returnKey, command, .send), (.returnKey, [.command, .shift], .sendAfterTurn),
            (.character("."), command, .stop), (.character("i"), command, .overview),
            (.character("\\"), command, .toggleSidebar), (.character("r"), command, .reloadPreview),
            (.character("s"), command, .save), (.leftArrow, [.command, .option], .previousFile),
            (.rightArrow, [.command, .option], .nextFile), (.character(","), command, .settings),
        ]
        for (key, modifiers, expected) in table {
            let matches = AppShortcut.allCases.filter {
                $0.key == key && $0.modifiers == modifiers && $0.scope != .sheet
            }
            #expect(matches == [expected], "\(expected) should be the only \(key) \(modifiers)")
        }
        #expect(AppShortcut.newShell.scope == .terminal && AppShortcut.closeShell.scope == .terminal)
        #expect(AppShortcut.findFile.scope == .files)
        #expect(AppShortcut.newShell.key == .character("t") && AppShortcut.closeShell.key == .character("w"))
        #expect(AppShortcut.addSubThread.key == .returnKey && AppShortcut.addSubThread.modifiers == .command)
        #expect(AppShortcut.addSubThread.scope == .sheet)
        #expect(AppShortcut.cancel.key == .escape && AppShortcut.cancel.modifiers.isEmpty)
        #expect(AppShortcut.cancel.scope == .sheet)
    }

    @Test func noTwoShortcutsCollide() {
        let scoped = Dictionary(grouping: AppShortcut.allCases, by: \.scope)
        let sceneCombinations = Set((scoped[.scene] ?? []).map(combination))
        #expect(sceneCombinations.count == (scoped[.scene] ?? []).count, "a scene shortcut repeats")
        for (scope, shortcuts) in scoped {
            let combinations = shortcuts.map(combination)
            #expect(Set(combinations).count == combinations.count, "\(scope) has a repeated shortcut")
            if scope != .scene {
                #expect(sceneCombinations.isDisjoint(with: combinations), "\(scope) reuses a scene shortcut")
            }
        }
    }

    @Test func everyShortcutReachableFromTheTerminalUsesCommand() {
        for shortcut in AppShortcut.allCases where shortcut.scope == .scene || shortcut.scope == .terminal {
            #expect(shortcut.modifiers.contains(.command), "\(shortcut) needs Command")
        }
    }

    @Test func escapeIsNeverInTheSceneOrTheTerminal() {
        for shortcut in AppShortcut.allCases where shortcut.key == .escape {
            #expect(shortcut.scope != .scene && shortcut.scope != .terminal)
        }
    }

    /// The terminal and the editor get Escape, Tab, arrows and Control combinations from the emulator and the text
    /// view first; the app takes only Command combinations, and a bare key only inside a sheet.
    @Test func noBareOrControlKeyIsBoundOutsideASheet() {
        for shortcut in AppShortcut.allCases where shortcut.scope != .sheet {
            #expect(shortcut.modifiers.contains(.command), "\(shortcut) would take a key the terminal needs")
            #expect(!shortcut.modifiers.contains(.control), "\(shortcut) uses Control")
            #expect(shortcut.key != .escape)
        }
    }

    @Test func paneNumbersFollowThePaneOrder() {
        let focus: [AppShortcut] = [.focusChat, .focusPreview, .focusFiles, .focusTerminal, .focusOverview]
        #expect(focus.map(\.pane) == [.chat, .preview, .files, .terminal, .overview])
        #expect(focus.map(\.pane) == PaneID.allCases)
        for (index, shortcut) in focus.enumerated() {
            #expect(shortcut.key == .character(Character(String(index + 1))))
            #expect(shortcut.modifiers == .command)
        }
        #expect(AppShortcut.allCases.filter { $0.pane != nil }.count == 5)
    }
}
