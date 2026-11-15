import PushTokenController from './PushTokenController'
import NotificationPreferenceController from './NotificationPreferenceController'
import LiveActivityController from './LiveActivityController'
import PushTestController from './PushTestController'
import LiveActivityChannelController from './LiveActivityChannelController'

const Push = {
    PushTokenController: Object.assign(PushTokenController, PushTokenController),
    NotificationPreferenceController: Object.assign(NotificationPreferenceController, NotificationPreferenceController),
    LiveActivityController: Object.assign(LiveActivityController, LiveActivityController),
    PushTestController: Object.assign(PushTestController, PushTestController),
    LiveActivityChannelController: Object.assign(LiveActivityChannelController, LiveActivityChannelController),
}

export default Push