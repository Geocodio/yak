import Foundation
import StoreKit
import YakKit

protocol BuildKindSource: Sendable {
    func kind() async -> DebugBuildKind
}

/// Reads the App Store environment the app reports, once, with a 5 second bound. A Debug build answers `.debug`
/// without asking.
struct BuildKindReader: BuildKindSource {
    func kind() async -> DebugBuildKind {
        #if DEBUG
            return .debug
        #else
            let environment = await withTaskGroup(of: String?.self) { group in
                group.addTask {
                    switch try? await AppTransaction.shared {
                    case .verified(let transaction)?, .unverified(let transaction, _)?:
                        return transaction.environment.rawValue
                    case nil: return nil
                    }
                }
                group.addTask {
                    try? await Task.sleep(for: .seconds(5))
                    return nil
                }
                let first = await group.next() ?? nil
                group.cancelAll()
                return first
            }
            return DebugMenuAvailability.kind(isDebugBuild: false, storeEnvironment: environment)
        #endif
    }
}
