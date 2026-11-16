public enum ObservationRowText {
    /// `repo` nil means the row shows the outcome alone.
    public static func subtitle(_ row: ObservationRow) -> (outcome: ObservationOutcome, repo: String?) {
        (row.outcome, row.repo)
    }
}
