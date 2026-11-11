import SessionStoreController from './SessionStoreController'
import DeploymentWakeController from './DeploymentWakeController'
import PreviewAuthController from './PreviewAuthController'
import DeploymentStatusController from './DeploymentStatusController'

const Internal = {
    SessionStoreController: Object.assign(SessionStoreController, SessionStoreController),
    DeploymentWakeController: Object.assign(DeploymentWakeController, DeploymentWakeController),
    PreviewAuthController: Object.assign(PreviewAuthController, PreviewAuthController),
    DeploymentStatusController: Object.assign(DeploymentStatusController, DeploymentStatusController),
}

export default Internal