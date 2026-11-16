import Foundation

// yak-stub --port <n> --fixtures <dir> --scenarios <dir> --scenario <name>
// Port 0 picks a free port. The port in use is printed as "listening on 127.0.0.1:<port>".
var options: [String: String] = [:]
var arguments = CommandLine.arguments.dropFirst().makeIterator()
while let flag = arguments.next() {
    guard flag.hasPrefix("--"), let value = arguments.next() else {
        log("usage: yak-stub --port <n> --fixtures <dir> --scenarios <dir> --scenario <name>")
        exit(2)
    }
    options[String(flag.dropFirst(2))] = value
}
guard let fixturesPath = options["fixtures"], let scenario = Scenario(rawValue: options["scenario"] ?? "paired"),
    let port = UInt16(options["port"] ?? "0")
else {
    log(
        "usage: yak-stub --port <n> --fixtures <dir> --scenarios <dir> --scenario <\(Scenario.allCases.map(\.rawValue).joined(separator: "|"))>"
    )
    exit(2)
}
do {
    let server = try StubServer(
        port: port,
        fixtures: StubFixtures(
            directory: URL(fileURLWithPath: fixturesPath),
            scenariosDirectory: options["scenarios"].map { URL(fileURLWithPath: $0) }),
        scenario: scenario,
        scenarios: options["scenarios"].map { URL(fileURLWithPath: $0) })
    let actualPort = try await server.start()
    try await server.startFeatures()
    FileHandle.standardOutput.write(Data("listening on 127.0.0.1:\(actualPort)\n".utf8))
    try await Task.sleep(for: .seconds(Int.max / 2))
} catch let error as StubFeatureError {
    log("yak-stub feature \(error.feature) \(error.isTimeout ? "did not start in time" : "failed to start")")
    exit(1)
} catch let error as StubFixtureError {
    log("yak-stub scenario file \(error.scenario).json is missing or unreadable (\(error.feature ?? "core"))")
    exit(1)
} catch {
    log("yak-stub failed: \(Swift.type(of: error))")
    exit(1)
}
