import SwiftUI
import UIKit
import YakKit

/// The commands card above the message field: sections of command rows, each the name in mono with its argument hint
/// and the description under it. Up to five rows show; more scroll inside the card. A tap fills the command into the
/// field and nothing is sent.
struct SlashMenuView: View {
    let menu: SlashMenu
    /// The tallest the card may be: the room above the field, five rows at most.
    let maxHeight: CGFloat
    let pick: (SlashCommand) -> Void

    var body: some View {
        // The card is as tall as its rows up to the cap; past it the rows scroll inside.
        ScrollViewReader { proxy in
            ScrollView { content }
                .scrollBounceBehavior(.basedOnSize)
                .frame(maxHeight: maxHeight)
                .fixedSize(horizontal: false, vertical: true)
                .onChange(of: menu.selected?.name) { _, name in
                    if let name { proxy.scrollTo(name) }
                }
        }
        .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.card))
        .overlay(
            RoundedRectangle(cornerRadius: Design.Radius.card)
                .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line)
        )
        .clipShape(.rect(cornerRadius: Design.Radius.card))
        .accessibilityElement(children: .contain)
        .accessibilityLabel(Text("composer.slash.label"))
        .accessibilityValue(
            menu.hasNoMatch ? Text("composer.slash.noMatch.short") : Text("composer.slash.count \(menu.rows.count)")
        )
        .accessibilityIdentifier("composer.slash")
        .onAppear { announce() }
        .onChange(of: menu.hasNoMatch) { announce() }
    }

    private func announce() {
        let sentence =
            menu.hasNoMatch
            ? String(localized: "composer.slash.noMatch \(menu.query)")
            : String(localized: "composer.slash.count \(menu.rows.count)")
        UIAccessibility.post(notification: .announcement, argument: sentence)
    }

    @ViewBuilder private var content: some View {
        VStack(alignment: .leading, spacing: 0) {
            if menu.hasNoMatch {
                Text("composer.slash.noMatch \(menu.query)").font(.yak(.footnote, .medium))
                    .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(Design.Space.medium)
                    .accessibilityIdentifier("composer.slash.noMatch")
            } else {
                ForEach(menu.groups, id: \.section) { group in
                    heading(group.section)
                    ForEach(group.commands) { row($0) }
                }
            }
        }
        .padding(Design.Space.small)
    }

    private func heading(_ section: SlashMenu.Section) -> some View {
        let title: LocalizedStringKey =
            switch section {
            case .skills: "composer.slash.section.skills"
            case .commands: "composer.slash.section.commands"
            case .yak: "composer.slash.section.yak"
            case .other: "composer.slash.section.other"
            }
        return Text(title).font(.yak(.caption2, .semibold)).textCase(.uppercase).foregroundStyle(Color.yakMuted)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, Design.Space.snug).padding(.top, Design.Space.compact)
            .padding(.bottom, Design.Space.tight)
            .accessibilityAddTraits(.isHeader)
    }

    private func row(_ command: SlashCommand) -> some View {
        let isSelected = command.name == menu.selected?.name
        return Button {
            pick(command)
        } label: {
            VStack(alignment: .leading, spacing: Design.Space.nudge) {
                name(command).fixedSize(horizontal: false, vertical: true)
                if !command.description.isEmpty {
                    Text(command.description).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, Design.Space.snug).padding(.vertical, Design.Space.compact)
            .frame(minHeight: Design.Size.control)
            .background(isSelected ? Color.yakBubble : Color.clear, in: .rect(cornerRadius: Design.Radius.row))
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .id(command.name)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier("composer.slash.row.\(command.name)")
    }

    /// `/name` in mono, and the hint after it in the muted color.
    private func name(_ command: SlashCommand) -> Text {
        var label = AttributedString("/" + command.name)
        if let hint = command.argumentHint, !hint.isEmpty {
            var hintText = AttributedString(" " + hint)
            hintText.foregroundColor = Color.yakMuted
            label.append(hintText)
        }
        return Text(label).font(.yak(.callout, isMonospaced: true))
    }
}
