import AssetController from './AssetController'
import Api from './Api'
import SpaController from './SpaController'

const Controllers = {
    AssetController: Object.assign(AssetController, AssetController),
    Api: Object.assign(Api, Api),
    SpaController: Object.assign(SpaController, SpaController),
}

export default Controllers