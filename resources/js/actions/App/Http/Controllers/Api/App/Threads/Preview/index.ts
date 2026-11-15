import PreviewStatusController from './PreviewStatusController'
import PreviewWarmController from './PreviewWarmController'
import PreviewHeartbeatController from './PreviewHeartbeatController'
import PreviewTicketController from './PreviewTicketController'

const Preview = {
    PreviewStatusController: Object.assign(PreviewStatusController, PreviewStatusController),
    PreviewWarmController: Object.assign(PreviewWarmController, PreviewWarmController),
    PreviewHeartbeatController: Object.assign(PreviewHeartbeatController, PreviewHeartbeatController),
    PreviewTicketController: Object.assign(PreviewTicketController, PreviewTicketController),
}

export default Preview