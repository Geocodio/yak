import PushTokenController from './PushTokenController'
import PushTestController from './PushTestController'

const Push = {
    PushTokenController: Object.assign(PushTokenController, PushTokenController),
    PushTestController: Object.assign(PushTestController, PushTestController),
}

export default Push