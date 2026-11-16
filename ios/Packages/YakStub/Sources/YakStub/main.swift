import Foundation

// yak-stub --port <n> --fixtures <dir> --scenario <name>
// Port 0 picks a free port. The port in use is printed as "listening on 127.0.0.1:<port>".
var options: [String: String] = [:]
var arguments = CommandLine.arguments.dropFirst().makeIterator()
while let flag = arguments.next() {
    guard flag.hasPrefix("--"), let value = arguments.next() else {
        log("usage: yak-stub --port <n> --fixtures <dir> --scenario <name>")
        exit(2)
    }
    options[String(flag.dropFirst(2))] = value
}
guard let fixturesPath = options["fixtures"], let scenario = Scenario(rawValue: options["scenario"] ?? "paired"),
    let port = UInt16(options["port"] ?? "0")
else {
    log(
        "usage: yak-stub --port <n> --fixtures <dir> --scenario <\(Scenario.allCases.map(\.rawValue).joined(separator: "|"))>"
    )
    exit(2)
}
do {
    let server = try StubServer(
        port: port, fixtures: StubFixtures(directory: URL(fileURLWithPath: fixturesPath)), scenario: scenario,
        scenarios: options["scenarios"].map { URL(fileURLWithPath: $0) })
    let actualPort = try await server.start()
    FileHandle.standardOutput.write(Data("listening on 127.0.0.1:\(actualPort)\n".utf8))
    try await Task.sleep(for: .seconds(Int.max / 2))
} catch {
    log("yak-stub failed: \(error)")
    exit(1)
}
