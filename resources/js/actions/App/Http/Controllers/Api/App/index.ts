import DeviceCodeController from './DeviceCodeController'
import DeviceTokenController from './DeviceTokenController'
import DevicePairController from './DevicePairController'
import DeviceController from './DeviceController'

const App = {
    DeviceCodeController: Object.assign(DeviceCodeController, DeviceCodeController),
    DeviceTokenController: Object.assign(DeviceTokenController, DeviceTokenController),
    DevicePairController: Object.assign(DevicePairController, DevicePairController),
    DeviceController: Object.assign(DeviceController, DeviceController),
}

export default App