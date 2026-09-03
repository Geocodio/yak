import DeploymentWakeController from './DeploymentWakeController'
import DeploymentStatusController from './DeploymentStatusController'

const Internal = {
    DeploymentWakeController: Object.assign(DeploymentWakeController, DeploymentWakeController),
    DeploymentStatusController: Object.assign(DeploymentStatusController, DeploymentStatusController),
}

export default Internal