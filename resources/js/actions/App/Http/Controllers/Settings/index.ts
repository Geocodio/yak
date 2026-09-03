import ProfileController from './ProfileController'
import AccountController from './AccountController'
import LinearConnectionController from './LinearConnectionController'
import VideoThemeController from './VideoThemeController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    AccountController: Object.assign(AccountController, AccountController),
    LinearConnectionController: Object.assign(LinearConnectionController, LinearConnectionController),
    VideoThemeController: Object.assign(VideoThemeController, VideoThemeController),
}

export default Settings