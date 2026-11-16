import PullRequestsShowController from './PullRequestsShowController'
import MergeOrderController from './MergeOrderController'
import AskToFixController from './AskToFixController'

const PullRequests = {
    PullRequestsShowController: Object.assign(PullRequestsShowController, PullRequestsShowController),
    MergeOrderController: Object.assign(MergeOrderController, MergeOrderController),
    AskToFixController: Object.assign(AskToFixController, AskToFixController),
}

export default PullRequests