import DeploymentWakeController from './DeploymentWakeController'
import DeploymentStatusController from './DeploymentStatusController'
import SessionStoreController from './SessionStoreController'

const Internal = {
    DeploymentWakeController: Object.assign(DeploymentWakeController, DeploymentWakeController),
    DeploymentStatusController: Object.assign(DeploymentStatusController, DeploymentStatusController),
    SessionStoreController: Object.assign(SessionStoreController, SessionStoreController),
}

export default Internal