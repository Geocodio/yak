import Testing

@testable import YakKit

@Test func connectionLineOnlyWhenNotFine() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(ConnectionLine.make(ConversationSnapshot(load: .slow)) == .slow)
    #expect(ConnectionLine.make(ConversationSnapshot(show: show, isOffline: true)) == .offlineSavedCopy)
    #expect(ConnectionLine.make(ConversationSnapshot(isOffline: true)) == .offline)
    #expect(
        ConnectionLine.make(ConversationSnapshot(load: .ready, show: show, connection: .reconnecting)) == .reconnecting)
    #expect(ConnectionLine.make(ConversationSnapshot(load: .ready, show: show, connection: .offline)) == nil)
    #expect(ConnectionLine.make(ConversationSnapshot(load: .ready, show: show, connection: .live)) == nil)
}
