import PushTokenController from './PushTokenController'
import NotificationPreferenceController from './NotificationPreferenceController'
import PushTestController from './PushTestController'

const Push = {
    PushTokenController: Object.assign(PushTokenController, PushTokenController),
    NotificationPreferenceController: Object.assign(NotificationPreferenceController, NotificationPreferenceController),
    PushTestController: Object.assign(PushTestController, PushTestController),
}

export default Push