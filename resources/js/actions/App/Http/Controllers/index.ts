import Auth from './Auth'
import Tasks from './Tasks'
import TaskAttachmentController from './TaskAttachmentController'
import CostDashboardController from './CostDashboardController'
import AnalyticsController from './AnalyticsController'
import Repositories from './Repositories'
import ObservationController from './ObservationController'
import HealthController from './HealthController'
import SystemController from './SystemController'
import ChannelController from './ChannelController'
import SkillController from './SkillController'
import MarketplaceController from './MarketplaceController'
import Settings from './Settings'
import PromptController from './PromptController'
import PromptPreviewController from './PromptPreviewController'
import PromptVersionController from './PromptVersionController'
import PrReviews from './PrReviews'
import Deployments from './Deployments'
import Threads from './Threads'
import Devices from './Devices'
import Cli from './Cli'
import Api from './Api'
import Internal from './Internal'
import ArtifactController from './ArtifactController'
import VideoThemeAssetController from './VideoThemeAssetController'

const Controllers = {
    Auth: Object.assign(Auth, Auth),
    Tasks: Object.assign(Tasks, Tasks),
    TaskAttachmentController: Object.assign(TaskAttachmentController, TaskAttachmentController),
    CostDashboardController: Object.assign(CostDashboardController, CostDashboardController),
    AnalyticsController: Object.assign(AnalyticsController, AnalyticsController),
    Repositories: Object.assign(Repositories, Repositories),
    ObservationController: Object.assign(ObservationController, ObservationController),
    HealthController: Object.assign(HealthController, HealthController),
    SystemController: Object.assign(SystemController, SystemController),
    ChannelController: Object.assign(ChannelController, ChannelController),
    SkillController: Object.assign(SkillController, SkillController),
    MarketplaceController: Object.assign(MarketplaceController, MarketplaceController),
    Settings: Object.assign(Settings, Settings),
    PromptController: Object.assign(PromptController, PromptController),
    PromptPreviewController: Object.assign(PromptPreviewController, PromptPreviewController),
    PromptVersionController: Object.assign(PromptVersionController, PromptVersionController),
    PrReviews: Object.assign(PrReviews, PrReviews),
    Deployments: Object.assign(Deployments, Deployments),
    Threads: Object.assign(Threads, Threads),
    Devices: Object.assign(Devices, Devices),
    Cli: Object.assign(Cli, Cli),
    Api: Object.assign(Api, Api),
    Internal: Object.assign(Internal, Internal),
    ArtifactController: Object.assign(ArtifactController, ArtifactController),
    VideoThemeAssetController: Object.assign(VideoThemeAssetController, VideoThemeAssetController),
}

export default Controllers