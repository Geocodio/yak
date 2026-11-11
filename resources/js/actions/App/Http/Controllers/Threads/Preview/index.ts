import ThreadPreviewStatusController from './ThreadPreviewStatusController'
import ThreadPreviewHeartbeatController from './ThreadPreviewHeartbeatController'
import ThreadPreviewWarmController from './ThreadPreviewWarmController'
import ThreadPreviewRestoreController from './ThreadPreviewRestoreController'

const Preview = {
    ThreadPreviewStatusController: Object.assign(ThreadPreviewStatusController, ThreadPreviewStatusController),
    ThreadPreviewHeartbeatController: Object.assign(ThreadPreviewHeartbeatController, ThreadPreviewHeartbeatController),
    ThreadPreviewWarmController: Object.assign(ThreadPreviewWarmController, ThreadPreviewWarmController),
    ThreadPreviewRestoreController: Object.assign(ThreadPreviewRestoreController, ThreadPreviewRestoreController),
}

export default Preview