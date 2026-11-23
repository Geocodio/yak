import XCTest

extension StubControl {
    var taskLink: URL { taskLink(id: "k3m9p2qa") }

    /// The link that opens the task `id`.
    func taskLink(id: String) -> URL {
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "task"
        components.queryItems = [
            URLQueryItem(name: "instance", value: url), URLQueryItem(name: "id", value: id),
        ]
        return components.url!
    }
}
