import Foundation
import Testing

@testable import YakKit

struct ComposerBarStateTests {
    @Test(arguments: [
        (
            ComposerState.idle, true, nil as TurnState?, "", 0, true, false, false, false,
            ComposerBarState.Placeholder.messageYak
        ),
        (.idle, true, nil, "Hi", 0, false, true, false, false, .messageYak),
        (.idle, true, nil, "  ", 1, true, true, false, false, .messageYak),
        (.idle, true, nil, "Hi", 1, false, true, false, false, .messageYak),
        (.working, true, nil, "", 0, true, false, true, false, .steerYak),
        (.idle, true, .running, "", 0, true, false, true, false, .steerYak),
        (.working, true, .running, "Use the draft names", 0, false, true, true, true, .steerYak),
        (.working, true, .completed, "", 0, true, false, false, false, .messageYak),
        (ComposerState(rawValue: "hibernating"), true, nil, "", 0, true, false, false, false, .messageYak),
    ])
    func theBarFollowsTheServer(
        composer: ComposerState, canDrive: Bool, turn: TurnState?, text: String, attachments: Int,
        plus: Bool, send: Bool, stop: Bool, sendAfterTurn: Bool, placeholder: ComposerBarState.Placeholder
    ) {
        let state = ComposerBarState.make(
            composer: composer, canDrive: canDrive,
            turn: turn.map { TurnChange(turnId: LenientID("41"), state: $0) }, notice: nil,
            typedText: text, attachmentCount: attachments)
        #expect(
            state.unavailable == nil && state.showsPlus == plus && state.showsSend == send && state.showsStop == stop)
        #expect(state.offersSendAfterTurn == sendAfterTurn && state.placeholder == placeholder)
    }

    @Test func stoppedAndLegacyKeepTheField() {
        let stopped = ComposerBarState.make(
            composer: .stopped, canDrive: true, turn: nil, notice: nil, typedText: "", attachmentCount: 0)
        #expect(stopped.unavailable == nil && stopped.placeholder == .replyToTryAgain && stopped.line == nil)
        let typing = ComposerBarState.make(
            composer: .stopped, canDrive: true, turn: nil, notice: nil, typedText: "Try the other branch",
            attachmentCount: 0)
        #expect(typing.showsSend && !typing.showsStop && typing.unavailable == nil)
        let legacy = ComposerBarState.make(
            composer: .legacy, canDrive: true, turn: nil, notice: nil, typedText: "Carry on", attachmentCount: 0)
        #expect(legacy.unavailable == nil && legacy.line == .startsNewThread && legacy.showsSend)
        #expect(legacy.placeholder == .messageYak)
        // A retry that is running is steered like any other turn.
        let running = ComposerBarState.make(
            composer: .stopped, canDrive: true, turn: TurnChange(turnId: LenientID("41"), state: .running),
            notice: nil, typedText: "", attachmentCount: 0)
        #expect(running.placeholder == .steerYak && running.showsStop)
        // A reader cannot send to either.
        let reader = ComposerBarState.make(
            composer: .legacy, canDrive: false, turn: nil, notice: nil, typedText: "", attachmentCount: 0)
        #expect(reader.unavailable == .readOnly && reader.line == nil)
    }

    @Test func theReasonReplacesTheField() {
        let notice = ThreadNotice(
            kind: "restore_failed", title: "Rebuilding the sandbox stopped at step 2",
            body: "Your message is saved.", resetsAt: nil, canContinue: false)
        let cases: [(ComposerState, Bool?, ThreadNotice?, ComposerUnavailable)] = [
            (.readonly, true, nil, .readOnly),
            (.idle, false, nil, .readOnly),
            (.disabledClosed, true, nil, .closed),
            (.disabledComputer, true, nil, .onComputer),
            (.disabledBlocked, true, notice, .blocked(title: notice.title, body: notice.body)),
            (.disabledBlocked, true, nil, .blocked(title: nil, body: nil)),
        ]
        for (composer, canDrive, noticeValue, expected) in cases {
            let state = ComposerBarState.make(
                composer: composer, canDrive: canDrive,
                turn: TurnChange(turnId: LenientID("41"), state: .running), notice: noticeValue,
                typedText: "Draft", attachmentCount: 0)
            #expect(state.unavailable == expected)
            #expect(!state.showsPlus && !state.showsSend && !state.showsStop && !state.offersSendAfterTurn)
            #expect(state.placeholder == .messageYak)
        }
    }

    @Test func aThreadStillLoadingAcceptsText() {
        let state = ComposerBarState.make(
            composer: nil, canDrive: nil, turn: nil, notice: nil, typedText: "Hi", attachmentCount: 0)
        #expect(state.unavailable == nil && state.showsSend)
    }
}
