import InstanceController from './InstanceController'
import DeviceCodeController from './DeviceCodeController'
import DeviceTokenController from './DeviceTokenController'
import DevicePairController from './DevicePairController'
import MeController from './MeController'
import DeviceController from './DeviceController'
import Threads from './Threads'
import BroadcastAuthController from './BroadcastAuthController'
import ObservationController from './ObservationController'
import Tasks from './Tasks'
import Push from './Push'
import WebSessionController from './WebSessionController'
import SignedMediaController from './SignedMediaController'

const App = {
    InstanceController: Object.assign(InstanceController, InstanceController),
    DeviceCodeController: Object.assign(DeviceCodeController, DeviceCodeController),
    DeviceTokenController: Object.assign(DeviceTokenController, DeviceTokenController),
    DevicePairController: Object.assign(DevicePairController, DevicePairController),
    MeController: Object.assign(MeController, MeController),
    DeviceController: Object.assign(DeviceController, DeviceController),
    Threads: Object.assign(Threads, Threads),
    BroadcastAuthController: Object.assign(BroadcastAuthController, BroadcastAuthController),
    ObservationController: Object.assign(ObservationController, ObservationController),
    Tasks: Object.assign(Tasks, Tasks),
    Push: Object.assign(Push, Push),
    WebSessionController: Object.assign(WebSessionController, WebSessionController),
    SignedMediaController: Object.assign(SignedMediaController, SignedMediaController),
}

export default App