import SessionStoreController from './SessionStoreController'
import ThreadPreviewWakeController from './ThreadPreviewWakeController'
import ThreadPreviewHostStatusController from './ThreadPreviewHostStatusController'
import SshAuthorizedKeysController from './SshAuthorizedKeysController'
import SshListenersController from './SshListenersController'
import SshWakeController from './SshWakeController'
import SshTerminalRedeemController from './SshTerminalRedeemController'
import SshActivityController from './SshActivityController'
import DeploymentWakeController from './DeploymentWakeController'
import PreviewAuthController from './PreviewAuthController'
import DeploymentStatusController from './DeploymentStatusController'

const Internal = {
    SessionStoreController: Object.assign(SessionStoreController, SessionStoreController),
    ThreadPreviewWakeController: Object.assign(ThreadPreviewWakeController, ThreadPreviewWakeController),
    ThreadPreviewHostStatusController: Object.assign(ThreadPreviewHostStatusController, ThreadPreviewHostStatusController),
    SshAuthorizedKeysController: Object.assign(SshAuthorizedKeysController, SshAuthorizedKeysController),
    SshListenersController: Object.assign(SshListenersController, SshListenersController),
    SshWakeController: Object.assign(SshWakeController, SshWakeController),
    SshTerminalRedeemController: Object.assign(SshTerminalRedeemController, SshTerminalRedeemController),
    SshActivityController: Object.assign(SshActivityController, SshActivityController),
    DeploymentWakeController: Object.assign(DeploymentWakeController, DeploymentWakeController),
    PreviewAuthController: Object.assign(PreviewAuthController, PreviewAuthController),
    DeploymentStatusController: Object.assign(DeploymentStatusController, DeploymentStatusController),
}

export default Internal