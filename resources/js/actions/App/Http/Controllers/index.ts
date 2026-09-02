import Auth from './Auth'
import Tasks from './Tasks'
import Repositories from './Repositories'
import ArtifactController from './ArtifactController'
import VideoThemeAssetController from './VideoThemeAssetController'
import Internal from './Internal'
import Deployments from './Deployments'

const Controllers = {
    Auth: Object.assign(Auth, Auth),
    Tasks: Object.assign(Tasks, Tasks),
    Repositories: Object.assign(Repositories, Repositories),
    ArtifactController: Object.assign(ArtifactController, ArtifactController),
    VideoThemeAssetController: Object.assign(VideoThemeAssetController, VideoThemeAssetController),
    Internal: Object.assign(Internal, Internal),
    Deployments: Object.assign(Deployments, Deployments),
}

export default Controllers