import Auth from './Auth'
import Tasks from './Tasks'
import CostDashboardController from './CostDashboardController'
import Repositories from './Repositories'
import Deployments from './Deployments'
import ArtifactController from './ArtifactController'
import VideoThemeAssetController from './VideoThemeAssetController'
import Internal from './Internal'

const Controllers = {
    Auth: Object.assign(Auth, Auth),
    Tasks: Object.assign(Tasks, Tasks),
    CostDashboardController: Object.assign(CostDashboardController, CostDashboardController),
    Repositories: Object.assign(Repositories, Repositories),
    Deployments: Object.assign(Deployments, Deployments),
    ArtifactController: Object.assign(ArtifactController, ArtifactController),
    VideoThemeAssetController: Object.assign(VideoThemeAssetController, VideoThemeAssetController),
    Internal: Object.assign(Internal, Internal),
}

export default Controllers