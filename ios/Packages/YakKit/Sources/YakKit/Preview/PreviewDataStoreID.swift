import Foundation

/// What a web data store of an instance is for. Each purpose has its own store, so a preview host (which may be a
/// same-site subdomain running branch code) never shares cookies with the embedded dashboard session.
public enum WebDataPurpose: CaseIterable, Sendable {
    case preview
    case embed
}

public enum PreviewDataStoreID {
    /// "YakPreviewStore1" and "YakEmbedStore001" as bytes. The preview constant must not change: stores of installed
    /// apps are named by it.
    private static func mask(_ purpose: WebDataPurpose) -> [UInt8] {
        switch purpose {
        case .preview:
            [0x59, 0x61, 0x6B, 0x50, 0x72, 0x65, 0x76, 0x69, 0x65, 0x77, 0x53, 0x74, 0x6F, 0x72, 0x65, 0x31]
        case .embed:
            [0x59, 0x61, 0x6B, 0x45, 0x6D, 0x62, 0x65, 0x64, 0x53, 0x74, 0x6F, 0x72, 0x65, 0x30, 0x30, 0x31]
        }
    }

    /// Every store an instance can have, one per purpose.
    public static func allStores(for instanceID: UUID) -> [UUID] {
        WebDataPurpose.allCases.map { Self.for(instanceID: instanceID, purpose: $0) }
    }

    /// Stable per instance and purpose: the instance id's bytes XOR a fixed constant, with the UUID version nibble 8 and the RFC 4122 variant.
    public static func `for`(instanceID: UUID, purpose: WebDataPurpose = .preview) -> UUID {
        let mask = mask(purpose)
        let source = instanceID.uuid
        var bytes = [
            source.0, source.1, source.2, source.3, source.4, source.5, source.6, source.7,
            source.8, source.9, source.10, source.11, source.12, source.13, source.14, source.15,
        ]
        for index in bytes.indices { bytes[index] ^= mask[index] }
        bytes[6] = (bytes[6] & 0x0F) | 0x80
        bytes[8] = (bytes[8] & 0x3F) | 0x80
        return UUID(
            uuid: (
                bytes[0], bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6], bytes[7],
                bytes[8], bytes[9], bytes[10], bytes[11], bytes[12], bytes[13], bytes[14], bytes[15]
            ))
    }

    /// Stores to erase: of the existing stores that this feature handed out (`known`, store ids), every one that belongs to no
    /// kept instance, plus the stores of pending instances. A store not in `known` is never listed, whoever made it.
    /// `keep` and `pending` are instance ids; an instance owns the store of every purpose. In the order of `existing`, no repeats.
    public static func toErase(existing: [UUID], known: [UUID], keep: [UUID], pending: [UUID]) -> [UUID] {
        let knownStores = Set(known)
        let keptStores = Set(keep.flatMap { Self.allStores(for: $0) })
        let pendingStores = Set(pending.flatMap { Self.allStores(for: $0) })
        var seen = Set<UUID>()
        return existing.filter { store in
            guard knownStores.contains(store), !keptStores.contains(store) || pendingStores.contains(store) else {
                return false
            }
            return seen.insert(store).inserted
        }
    }
}
