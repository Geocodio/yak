import Testing

@testable import YakKit

@Test func elapsedTextShowsMinutesAndSeconds() {
    #expect(ElapsedText.make(seconds: 0) == "0:00")
    #expect(ElapsedText.make(seconds: 7.9) == "0:07")
    #expect(ElapsedText.make(seconds: 723) == "12:03")
}

@Test func elapsedTextAddsHoursWhenThereAreSome() {
    #expect(ElapsedText.make(seconds: 3600) == "1:00:00")
    #expect(ElapsedText.make(seconds: 7383) == "2:03:03")
}

@Test func elapsedTextReadsNegativeAndBrokenValuesAsZero() {
    #expect(ElapsedText.make(seconds: -5) == "0:00")
    #expect(ElapsedText.make(seconds: .nan) == "0:00")
    #expect(ElapsedText.make(seconds: .infinity) == "0:00")
}
