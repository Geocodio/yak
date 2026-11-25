/// How far the tap area of a pull request number reaches past its text. A target never reaches into another line or
/// another target: not up into the detail line, not down into the next line of badges, and between two numbers each
/// side stops at the middle of the separator (the view builds the halves into the numbers themselves, so the inner
/// reach is 0 here). Below, the target uses the row's own bottom padding, and only where the number sits on the last
/// line of the row.
public enum ReviewHitArea {
    public struct Reach: Equatable, Sendable {
        public let top: Double
        public let bottom: Double
        public let leading: Double
        public let trailing: Double
    }

    /// The comfortable size of a target, in points.
    public static let comfortableSize: Double = 44

    /// - Parameters:
    ///   - isFirst: the first number of the line; only it reaches to the leading side.
    ///   - isLast: the last number of the line; only it reaches to the trailing side.
    ///   - isOnLastLine: the number's line is the row's last, with nothing under it but the row's padding.
    ///   - bottomPadding: the row's own padding under its last line.
    ///   - lineHeight: the height of the number's text.
    ///   - outerReach: how far the first and the last number reach sideways.
    public static func reach(
        isFirst: Bool, isLast: Bool, isOnLastLine: Bool, bottomPadding: Double, lineHeight: Double,
        outerReach: Double
    ) -> Reach {
        let wanted = max(0, comfortableSize - lineHeight)
        let bottom = isOnLastLine ? min(max(0, bottomPadding), wanted) : 0
        return Reach(top: 0, bottom: bottom, leading: isFirst ? outerReach : 0, trailing: isLast ? outerReach : 0)
    }
}
