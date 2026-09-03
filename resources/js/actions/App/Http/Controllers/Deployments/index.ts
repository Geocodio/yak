import DeploymentController from './DeploymentController'
import DeploymentActionController from './DeploymentActionController'
import ShareLinkController from './ShareLinkController'
import AuthBounceController from './AuthBounceController'

const Deployments = {
    DeploymentController: Object.assign(DeploymentController, DeploymentController),
    DeploymentActionController: Object.assign(DeploymentActionController, DeploymentActionController),
    ShareLinkController: Object.assign(ShareLinkController, ShareLinkController),
    AuthBounceController: Object.assign(AuthBounceController, AuthBounceController),
}

export default Deployments