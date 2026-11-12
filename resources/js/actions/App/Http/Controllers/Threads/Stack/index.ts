import ChildOverrideController from './ChildOverrideController'
import StackLimitController from './StackLimitController'
import StackStopController from './StackStopController'
import StackShowController from './StackShowController'
import StackChildStoreController from './StackChildStoreController'
import ChildDiffController from './ChildDiffController'

const Stack = {
    ChildOverrideController: Object.assign(ChildOverrideController, ChildOverrideController),
    StackLimitController: Object.assign(StackLimitController, StackLimitController),
    StackStopController: Object.assign(StackStopController, StackStopController),
    StackShowController: Object.assign(StackShowController, StackShowController),
    StackChildStoreController: Object.assign(StackChildStoreController, StackChildStoreController),
    ChildDiffController: Object.assign(ChildDiffController, ChildDiffController),
}

export default Stack