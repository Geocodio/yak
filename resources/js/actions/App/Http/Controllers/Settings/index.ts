import ProfileController from './ProfileController'
import AccountController from './AccountController'
import LinearConnectionController from './LinearConnectionController'
import VideoThemeController from './VideoThemeController'
import McpServerController from './McpServerController'
import McpLoginController from './McpLoginController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    AccountController: Object.assign(AccountController, AccountController),
    LinearConnectionController: Object.assign(LinearConnectionController, LinearConnectionController),
    VideoThemeController: Object.assign(VideoThemeController, VideoThemeController),
    McpServerController: Object.assign(McpServerController, McpServerController),
    McpLoginController: Object.assign(McpLoginController, McpLoginController),
}

export default Settings