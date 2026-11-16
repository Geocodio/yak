/// The version of the app-to-server protocol this build speaks.
public enum ClientProtocol {
    public static let current = 1
    public static let minimumServer = 1
    public static let headerName = "X-Yak-Protocol"
    public static let minimumHeaderName = "X-Yak-Min-Protocol"
}
