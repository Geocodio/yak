import Foundation
import WidgetKit
import YakKit

#if canImport(os)
    import os
#endif

/// The system hands the extension its widget push token here. It is stored for every process and registered with
/// each instance that can push to this build, within 10 seconds; a registration that does not finish stays pending in
/// the app group and resumes on the next timeline reload.
struct YakWidgetPushHandler: WidgetPushHandler {
    func pushTokenDidChange(_ pushInfo: WidgetPushInfo, widgets: [WidgetInfo]) {
        let token = PushRegistrar.hex(pushInfo.token)
        Task {
            guard let services = WidgetServices.open() else { return }
            let statuses = await services.registration.tokenChanged(token, budget: .seconds(10))
            #if canImport(os)
                let registered = statuses.values.filter { $0 == .registered }.count
                Logger(subsystem: "Yak", category: "WidgetPush")
                    .info("registered \(registered, privacy: .public) of \(statuses.count, privacy: .public)")
            #endif
        }
    }
}
