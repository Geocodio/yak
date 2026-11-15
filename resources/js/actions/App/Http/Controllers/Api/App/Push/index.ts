import PushTokenController from './PushTokenController'
import NotificationPreferenceController from './NotificationPreferenceController'
import PushTestController from './PushTestController'
import LiveActivityChannelController from './LiveActivityChannelController'

const Push = {
    PushTokenController: Object.assign(PushTokenController, PushTokenController),
    NotificationPreferenceController: Object.assign(NotificationPreferenceController, NotificationPreferenceController),
    PushTestController: Object.assign(PushTestController, PushTestController),
    LiveActivityChannelController: Object.assign(LiveActivityChannelController, LiveActivityChannelController),
}

export default Push