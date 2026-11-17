import UIKit

/// The share sheet's principal class. The screen arrives with the share screen task; until then it closes the request.
final class ShareViewController: UIViewController {
    override func viewDidLoad() {
        super.viewDidLoad()
        extensionContext?.cancelRequest(withError: CocoaError(.userCancelled))
    }
}
