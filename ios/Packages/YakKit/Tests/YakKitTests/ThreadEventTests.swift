import Foundation
import Testing

@testable import YakKit

@Test(arguments: ["simple-turn", "steer", "question", "failed-turn", "stream-reset", "pointer"])
func everyEventListDecodesWithoutUnknowns(name: String) throws {
    let list = try EventList.load("events/\(name)")
    #expect(list.events.allSatisfy { $0.payload != .unknown })
    #expect(list.stored == list.events.filter(\.isStored))
}

@Test func aPointerIsResolvedFromThePointerList() throws {
    let list = try EventList.load("events/pointer")
    #expect(list.broadcasts[2] == .pointer(sequence: 3))
    guard case .entry(.toolResult("toolu_6", false, let text)) = list.events[2].payload else {
        Issue.record("pointer event")
        return
    }
    #expect(text.count == 9000)
}

@Test func decodesEachPayloadKind() throws {
    let events = try EventList.load("events/simple-turn").events
    #expect(
        events[1].payload
            == .entry(
                .user(
                    uuid: "00000000-0000-4000-8000-000000000001",
                    text: "Retry failed rows when the import times out", kind: "prompt")))
    #expect(events[2].payload == .turn(TurnChange(turnId: LenientID("41"), state: .running)))
    #expect(events[5].payload == .delta(StreamDelta(blockKey: "msg_1:0", kind: "text", text: "Looking at ")))
    guard case .entry(.assistant("msg_1", let blocks)) = events[7].payload else {
        Issue.record("assistant")
        return
    }
    #expect(
        blocks[1]
            == .toolUse(id: "toolu_1", name: "Read", input: ["file_path": .string("app/Importer.php")]))
}

@Test func anEventPageSurvivesOneBadEvent() throws {
    let json = """
        {"events":[
          {"sequence":1,"type":"turn","createdAt":"t","data":{"turnId":1,"state":"running"}},
          {"sequence":"two","type":"turn","createdAt":"t","data":{}},
          {"sequence":3,"type":"turn","createdAt":"t","data":{"turnId":"x"}},
          "not an object",
          {"sequence":4,"type":"question","createdAt":"t","data":{"open":true}}
        ],"latestSequence":4,"source":"window"}
        """
    let page = try JSONDecoder().decode(EventsPage.self, from: Data(json.utf8))
    #expect(page.events.map(\.sequence) == [1, 3, 4])
    #expect(page.events[1].payload == .unknown)
    #expect(page.latestSequence == 4)
}

@Test func unknownTypesDecodeAsUnknown() throws {
    let event = try JSONDecoder().decode(
        ThreadEvent.self, from: Data(#"{"sequence":5,"type":"mystery","createdAt":"t","data":{"a":1}}"#.utf8))
    #expect(event.sequence == 5 && event.payload == .unknown)
}

@Test func unknownRolesAndBlocksStayVisibleAsUnknown() throws {
    let role = ThreadEvent(sequence: 1, type: "entry", createdAt: "t", data: .object(["role": .string("robot")]))
    #expect(role.payload == .entry(.unknown(role: "robot")))
    let data = try JSONDecoder().decode(
        JSONValue.self,
        from: Data(
            #"{"role":"assistant","messageId":"m","blocks":[{"type":"hologram"},{"type":"tool_use"},{"type":"thinking","text":"hm"}]}"#
                .utf8))
    let entry = ThreadEvent(sequence: 2, type: "entry", createdAt: "t", data: data)
    #expect(
        entry.payload
            == .entry(
                .assistant(
                    messageId: "m",
                    blocks: [.unknown(type: "hologram"), .unknown(type: "tool_use"), .thinking("hm")])))
}

@Test func readsSocketPayloads() throws {
    #expect(SocketEvent.decode(Data(#"{"sequence":9,"pointer":true}"#.utf8)) == .pointer(sequence: 9))
    #expect(SocketEvent.decode(Data("not json".utf8)) == nil)
    #expect(SocketEvent.decode(Data(#"{"hello":1}"#.utf8)) == nil)
    guard case .event(let event)? = SocketEvent.decode(try Scenarios.data("scenario-thread-event")) else {
        Issue.record("event")
        return
    }
    #expect(event.sequence == 8)
}

@Test func anEventEncodesBackToItsOriginalData() throws {
    let event = try EventList.load("events/simple-turn").events[7]
    let again = try JSONDecoder().decode(ThreadEvent.self, from: JSONEncoder().encode(event))
    #expect(again == event && again.payload == event.payload)
}

@Test func decodesChannelAuthorizations() throws {
    let priv = try decoded(ChannelAuthorization.self, "scenario-broadcasting-auth-private")
    #expect(priv.channelData == nil)
    let presence = try decoded(ChannelAuthorization.self, "scenario-broadcasting-auth-presence")
    #expect(presence.channelData?.contains("user_id") == true)
}

@Test func deltaAndResetAreNotStored() throws {
    let events = try EventList.load("events/stream-reset").events
    #expect(events.filter { !$0.isStored }.count == 4)
    let question = try EventList.load("events/question").events
    #expect(question.last?.isStored == true)
    #expect(question.first { $0.type == "entry" }?.isStored == true)
}
