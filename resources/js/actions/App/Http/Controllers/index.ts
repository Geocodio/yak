import Auth from './Auth'
import Tasks from './Tasks'
import CostDashboardController from './CostDashboardController'
import Repositories from './Repositories'
import HealthController from './HealthController'
import ChannelController from './ChannelController'
import SkillController from './SkillController'
import MarketplaceController from './MarketplaceController'
import PromptController from './PromptController'
import PromptPreviewController from './PromptPreviewController'
import PromptVersionController from './PromptVersionController'
import PrReviews from './PrReviews'
import Deployments from './Deployments'
import ArtifactController from './ArtifactController'
import VideoThemeAssetController from './VideoThemeAssetController'
import Internal from './Internal'
import Settings from './Settings'

const Controllers = {
    Auth: Object.assign(Auth, Auth),
    Tasks: Object.assign(Tasks, Tasks),
    CostDashboardController: Object.assign(CostDashboardController, CostDashboardController),
    Repositories: Object.assign(Repositories, Repositories),
    HealthController: Object.assign(HealthController, HealthController),
    ChannelController: Object.assign(ChannelController, ChannelController),
    SkillController: Object.assign(SkillController, SkillController),
    MarketplaceController: Object.assign(MarketplaceController, MarketplaceController),
    PromptController: Object.assign(PromptController, PromptController),
    PromptPreviewController: Object.assign(PromptPreviewController, PromptPreviewController),
    PromptVersionController: Object.assign(PromptVersionController, PromptVersionController),
    PrReviews: Object.assign(PrReviews, PrReviews),
    Deployments: Object.assign(Deployments, Deployments),
    ArtifactController: Object.assign(ArtifactController, ArtifactController),
    VideoThemeAssetController: Object.assign(VideoThemeAssetController, VideoThemeAssetController),
    Internal: Object.assign(Internal, Internal),
    Settings: Object.assign(Settings, Settings),
}

export default Controllers