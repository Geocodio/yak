import SwiftUI
import WidgetKit

@main
struct YakWidgetsBundle: WidgetBundle {
    var body: some Widget {
        NeedsYouWidget()
        RunningThreadsWidget()
        ThreadLiveActivity()
    }
}
