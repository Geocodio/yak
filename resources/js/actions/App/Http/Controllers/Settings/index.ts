import McpServerController from './McpServerController'
import McpLoginController from './McpLoginController'
import ClaudeAccountController from './ClaudeAccountController'
import DeviceController from './DeviceController'
import SshKeyController from './SshKeyController'
import ProfileController from './ProfileController'
import EmailAddressController from './EmailAddressController'
import GitHubAccountController from './GitHubAccountController'
import AccountController from './AccountController'
import LinearConnectionController from './LinearConnectionController'
import NotificationController from './NotificationController'
import PushDeviceController from './PushDeviceController'
import VideoThemeController from './VideoThemeController'

const Settings = {
    McpServerController: Object.assign(McpServerController, McpServerController),
    McpLoginController: Object.assign(McpLoginController, McpLoginController),
    ClaudeAccountController: Object.assign(ClaudeAccountController, ClaudeAccountController),
    DeviceController: Object.assign(DeviceController, DeviceController),
    SshKeyController: Object.assign(SshKeyController, SshKeyController),
    ProfileController: Object.assign(ProfileController, ProfileController),
    EmailAddressController: Object.assign(EmailAddressController, EmailAddressController),
    GitHubAccountController: Object.assign(GitHubAccountController, GitHubAccountController),
    AccountController: Object.assign(AccountController, AccountController),
    LinearConnectionController: Object.assign(LinearConnectionController, LinearConnectionController),
    NotificationController: Object.assign(NotificationController, NotificationController),
    PushDeviceController: Object.assign(PushDeviceController, PushDeviceController),
    VideoThemeController: Object.assign(VideoThemeController, VideoThemeController),
}

export default Settings