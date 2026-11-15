import PreviewStatusController from './PreviewStatusController'
import PreviewWarmController from './PreviewWarmController'
import PreviewHeartbeatController from './PreviewHeartbeatController'

const Preview = {
    PreviewStatusController: Object.assign(PreviewStatusController, PreviewStatusController),
    PreviewWarmController: Object.assign(PreviewWarmController, PreviewWarmController),
    PreviewHeartbeatController: Object.assign(PreviewHeartbeatController, PreviewHeartbeatController),
}

export default Preview