import InstanceController from './InstanceController'
import DeviceCodeController from './DeviceCodeController'
import DeviceTokenController from './DeviceTokenController'
import DevicePairController from './DevicePairController'
import MeController from './MeController'
import DeviceController from './DeviceController'

const App = {
    InstanceController: Object.assign(InstanceController, InstanceController),
    DeviceCodeController: Object.assign(DeviceCodeController, DeviceCodeController),
    DeviceTokenController: Object.assign(DeviceTokenController, DeviceTokenController),
    DevicePairController: Object.assign(DevicePairController, DevicePairController),
    MeController: Object.assign(MeController, MeController),
    DeviceController: Object.assign(DeviceController, DeviceController),
}

export default App