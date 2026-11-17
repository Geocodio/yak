import RepositoryController from './RepositoryController'
import GitHubRepoSearchController from './GitHubRepoSearchController'
import GitHubCiDetectController from './GitHubCiDetectController'
import RiskProfileController from './RiskProfileController'
import ApprovalSettingsController from './ApprovalSettingsController'
import RepositorySettingsController from './RepositorySettingsController'
import RepositoryActionController from './RepositoryActionController'
import ManifestController from './ManifestController'
import RepositoryClaudeAccountController from './RepositoryClaudeAccountController'

const Repositories = {
    RepositoryController: Object.assign(RepositoryController, RepositoryController),
    GitHubRepoSearchController: Object.assign(GitHubRepoSearchController, GitHubRepoSearchController),
    GitHubCiDetectController: Object.assign(GitHubCiDetectController, GitHubCiDetectController),
    RiskProfileController: Object.assign(RiskProfileController, RiskProfileController),
    ApprovalSettingsController: Object.assign(ApprovalSettingsController, ApprovalSettingsController),
    RepositorySettingsController: Object.assign(RepositorySettingsController, RepositorySettingsController),
    RepositoryActionController: Object.assign(RepositoryActionController, RepositoryActionController),
    ManifestController: Object.assign(ManifestController, ManifestController),
    RepositoryClaudeAccountController: Object.assign(RepositoryClaudeAccountController, RepositoryClaudeAccountController),
}

export default Repositories