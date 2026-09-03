import McpServerController from './McpServerController'
import McpLoginController from './McpLoginController'
import ProfileController from './ProfileController'
import AccountController from './AccountController'
import LinearConnectionController from './LinearConnectionController'
import VideoThemeController from './VideoThemeController'

const Settings = {
    McpServerController: Object.assign(McpServerController, McpServerController),
    McpLoginController: Object.assign(McpLoginController, McpLoginController),
    ProfileController: Object.assign(ProfileController, ProfileController),
    AccountController: Object.assign(AccountController, AccountController),
    LinearConnectionController: Object.assign(LinearConnectionController, LinearConnectionController),
    VideoThemeController: Object.assign(VideoThemeController, VideoThemeController),
}

export default Settings