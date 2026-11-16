import DeviceCodeController from './DeviceCodeController'
import DeviceTokenController from './DeviceTokenController'
import MeController from './MeController'
import DeviceController from './DeviceController'
import RepositoryResolveController from './RepositoryResolveController'

const Cli = {
    DeviceCodeController: Object.assign(DeviceCodeController, DeviceCodeController),
    DeviceTokenController: Object.assign(DeviceTokenController, DeviceTokenController),
    MeController: Object.assign(MeController, MeController),
    DeviceController: Object.assign(DeviceController, DeviceController),
    RepositoryResolveController: Object.assign(RepositoryResolveController, RepositoryResolveController),
}

export default Cli