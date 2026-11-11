import ThreadPreviewStatusController from './ThreadPreviewStatusController'
import ThreadPreviewHeartbeatController from './ThreadPreviewHeartbeatController'
import ThreadPreviewRestoreController from './ThreadPreviewRestoreController'

const Preview = {
    ThreadPreviewStatusController: Object.assign(ThreadPreviewStatusController, ThreadPreviewStatusController),
    ThreadPreviewHeartbeatController: Object.assign(ThreadPreviewHeartbeatController, ThreadPreviewHeartbeatController),
    ThreadPreviewRestoreController: Object.assign(ThreadPreviewRestoreController, ThreadPreviewRestoreController),
}

export default Preview