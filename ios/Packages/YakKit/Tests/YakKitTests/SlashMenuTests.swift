import Foundation
import Testing

@testable import YakKit

private let commands = [
    SlashCommand(name: "write-tests", description: "Add tests", kind: .skill),
    SlashCommand(name: "review-pr", description: "Review a pull request", argumentHint: "[number]", kind: .skill),
    SlashCommand(name: "compact", description: "Summarise", kind: .command),
    SlashCommand(name: "init", description: "Write a CLAUDE.md", kind: .command),
    SlashCommand(name: "clear", description: "Fresh thread", kind: .yak),
    SlashCommand(name: "preview", description: "Newer kind", kind: SlashCommandKind(rawValue: "plugin")),
]

@Test func theMenuOpensOnlyWhenTheWholeFieldIsASlashAndAName() {
    func isOpen(_ text: String, commands: [SlashCommand]? = commands, dismissed: Bool = false) -> Bool {
        SlashMenu.make(commands: commands, text: text, isDismissed: dismissed) != nil
    }
    #expect(isOpen("/") && isOpen("/re") && isOpen("/review-pr"))
    #expect(!isOpen("") && !isOpen("re") && !isOpen(" /re") && !isOpen("/review-pr ") && !isOpen("/re\nview"))
    #expect(!isOpen("/re", commands: nil), "an older server has no menu, so the slash is text")
    #expect(!isOpen("/", commands: []), "a bare slash with nothing to list stays closed")
    #expect(isOpen("/xyz", commands: []), "a typed name with no match says so")
    #expect(!isOpen("/re", dismissed: true))
}

@Test func filteringPutsPrefixMatchesFirstThenOthersEachInServerOrder() {
    #expect(SlashMenu.filter(commands, query: "re").map(\.name) == ["review-pr", "preview"])
    #expect(SlashMenu.filter(commands, query: "RE").map(\.name) == ["review-pr", "preview"])
    #expect(SlashMenu.filter(commands, query: "c").map(\.name) == ["compact", "clear"])
    #expect(SlashMenu.filter(commands, query: "").count == commands.count)
    #expect(SlashMenu.filter(commands, query: "zzz").isEmpty)
}

@Test func theMenuGroupsByKindAndKeepsAnUnknownKind() throws {
    let menu = try #require(SlashMenu.make(commands: commands, text: "/", isDismissed: false))
    #expect(menu.groups.map(\.section) == [.skills, .commands, .yak, .other])
    #expect(menu.rows.map(\.name) == ["write-tests", "review-pr", "compact", "init", "clear", "preview"])
    #expect(menu.selected?.name == "write-tests")
    let none = try #require(SlashMenu.make(commands: commands, text: "/xyz", isDismissed: false))
    #expect(none.hasNoMatch && none.selected == nil && none.query == "xyz" && none.moved(.down) == nil)
}

@Test func theArrowKeysWrapAndAHighlightThatLeftTheListFallsBackToTheFirstRow() throws {
    func menu(_ highlighted: String?) throws -> SlashMenu {
        try #require(SlashMenu.make(commands: commands, text: "/", isDismissed: false, highlighted: highlighted))
    }
    #expect(try menu(nil).moved(.down) == "review-pr")
    #expect(try menu(nil).moved(.up) == "preview")
    #expect(try menu("preview").moved(.down) == "write-tests")
    #expect(try menu("clear").selected?.name == "clear")
    #expect(try menu("gone").selected?.name == "write-tests")
    let filtered = try #require(
        SlashMenu.make(commands: commands, text: "/re", isDismissed: false, highlighted: "init"))
    #expect(filtered.selected?.name == "review-pr")
}

@Test func aPickAlwaysWritesTheNameAndASpace() {
    #expect(SlashMenu.pick(commands[1]) == SlashMenu.Pick(text: "/review-pr ", caret: 11))
    #expect(SlashMenu.pick(commands[4]) == SlashMenu.Pick(text: "/clear ", caret: 7))
    // The space ends the name, so the menu is closed by its own rule.
    #expect(SlashMenu.make(commands: commands, text: "/clear ", isDismissed: false) == nil)
}

@Test func keysGoToTheMenuOnlyWhenItCanUseThem() throws {
    let menu = SlashMenu.make(commands: commands, text: "/re", isDismissed: false)
    let none = SlashMenu.make(commands: commands, text: "/zzz", isDismissed: false)
    let review = try #require(menu?.selected)
    #expect(SlashKeyRouting.action(for: .down, menu: menu, isComposing: false) == .move(.down))
    #expect(SlashKeyRouting.action(for: .up, menu: menu, isComposing: false) == .move(.up))
    #expect(SlashKeyRouting.action(for: .returnKey, menu: menu, isComposing: false) == .pick(review))
    #expect(SlashKeyRouting.action(for: .escape, menu: menu, isComposing: false) == .dismiss)
    // An input method that is composing gets every key.
    for key in [SlashKey.up, .down, .returnKey, .escape] {
        #expect(SlashKeyRouting.action(for: key, menu: menu, isComposing: true) == nil)
        #expect(SlashKeyRouting.action(for: key, menu: nil, isComposing: false) == nil)
    }
    // No match: arrows and Return stay with the field, Escape closes.
    #expect(SlashKeyRouting.action(for: .down, menu: none, isComposing: false) == nil)
    #expect(SlashKeyRouting.action(for: .returnKey, menu: none, isComposing: false) == nil)
    #expect(SlashKeyRouting.action(for: .escape, menu: none, isComposing: false) == .dismiss)
    #expect(SlashKeyRouting.keys(menu: menu, isComposing: false) == [.up, .down, .returnKey, .escape])
    #expect(SlashKeyRouting.keys(menu: none, isComposing: false) == [.escape])
    #expect(
        SlashKeyRouting.keys(menu: menu, isComposing: true).isEmpty
            && SlashKeyRouting.keys(menu: nil, isComposing: false).isEmpty)
}

@Test func theMenuHeightFollowsTheRoomAboveTheField() {
    func cap(bottom: Double) -> Double {
        SlashMenuHeight.cap(
            barBottom: bottom, cardHeight: 60, spacing: 6, topReserve: 60, oneRow: 60, fiveRows: 324)
    }
    #expect(cap(bottom: 800) == 324, "a tall phone gets five rows")
    #expect(cap(bottom: 400) == 274, "less room, less menu")
    // A small phone in landscape: 375 points high, about 200 under the keyboard.
    #expect(cap(bottom: 175) == 60, "a short window still shows one row to scroll")
}

@Test func slashCommandsDecodeTolerantly() throws {
    let json = Data(
        """
        {"commands":[
          {"name":"review","description":"Review","argumentHint":"[focus]","kind":"skill"},
          {"name":"odd","description":"Newer","argumentHint":null,"kind":"hologram"},
          {"description":"no name","kind":"skill"},
          {"name":"nodesc","kind":"skill"},
          {"name":"","description":"empty","kind":"skill"},
          {"name":"nokind","description":"x"}],
         "capturedAt":"2026-11-15T12:00:00+00:00"}
        """.utf8)
    let decoded = try JSONDecoder().decode(SlashCommands.self, from: json)
    #expect(decoded.commands.map(\.name) == ["review", "odd"])
    #expect(decoded.commands[1].kind.rawValue == "hologram" && decoded.commands[1].argumentHint == nil)
    #expect(decoded.capturedAt == "2026-11-15T12:00:00+00:00")
    #expect(try JSONDecoder().decode(SlashCommands.self, from: Data("{}".utf8)).commands.isEmpty)
}

@Test func theThreadShowCarriesTheCommandsAndAnOlderServerHasNone() throws {
    let show = try decoded(ThreadShow.self, "scenario-overview-thread-show")
    #expect(show.slashCommands == nil)
}

@Test func theServersComposerFixtureDecodesItsCommands() throws {
    let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data("thread-composer"))
    let list = try #require(show.slashCommands)
    #expect(list.commands.map(\.name) == ["clear", "review"])
    #expect(list.commands.map(\.kind) == [.yak, .skill])
    #expect(list.commands[1].argumentHint == "[focus]" && list.capturedAt != nil)
    let menu = try #require(SlashMenu.make(commands: list.commands, text: "/re", isDismissed: false))
    #expect(menu.rows.map(\.name) == ["review"])
}
