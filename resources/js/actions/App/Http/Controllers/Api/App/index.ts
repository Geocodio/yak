import DeviceCodeController from './DeviceCodeController'
import DeviceTokenController from './DeviceTokenController'
import DeviceController from './DeviceController'

const App = {
    DeviceCodeController: Object.assign(DeviceCodeController, DeviceCodeController),
    DeviceTokenController: Object.assign(DeviceTokenController, DeviceTokenController),
    DeviceController: Object.assign(DeviceController, DeviceController),
}

export default App