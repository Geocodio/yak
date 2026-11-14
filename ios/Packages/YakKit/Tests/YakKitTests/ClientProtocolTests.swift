import Testing
@testable import YakKit

@Test func protocolNumberIsOne() {
    #expect(ClientProtocol.current == 1)
    #expect(ClientProtocol.headerName == "X-Yak-Protocol")
}
