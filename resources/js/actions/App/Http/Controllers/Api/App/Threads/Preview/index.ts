import PreviewStatusController from './PreviewStatusController'
import PreviewWarmController from './PreviewWarmController'
import PreviewHeartbeatController from './PreviewHeartbeatController'
import PreviewTicketController from './PreviewTicketController'
import PreviewRestoreController from './PreviewRestoreController'

const Preview = {
    PreviewStatusController: Object.assign(PreviewStatusController, PreviewStatusController),
    PreviewWarmController: Object.assign(PreviewWarmController, PreviewWarmController),
    PreviewHeartbeatController: Object.assign(PreviewHeartbeatController, PreviewHeartbeatController),
    PreviewTicketController: Object.assign(PreviewTicketController, PreviewTicketController),
    PreviewRestoreController: Object.assign(PreviewRestoreController, PreviewRestoreController),
}

export default Preview