import ThreadLookupController from './ThreadLookupController'
import TeleportController from './TeleportController'
import StackMessageController from './StackMessageController'
import SshLookupController from './SshLookupController'
import SessionFileController from './SessionFileController'

const Cli = {
    ThreadLookupController: Object.assign(ThreadLookupController, ThreadLookupController),
    TeleportController: Object.assign(TeleportController, TeleportController),
    StackMessageController: Object.assign(StackMessageController, StackMessageController),
    SshLookupController: Object.assign(SshLookupController, SshLookupController),
    SessionFileController: Object.assign(SessionFileController, SessionFileController),
}

export default Cli