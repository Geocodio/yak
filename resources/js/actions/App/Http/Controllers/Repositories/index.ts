import RepositoryController from './RepositoryController'
import GitHubRepoSearchController from './GitHubRepoSearchController'
import GitHubCiDetectController from './GitHubCiDetectController'
import RiskProfileController from './RiskProfileController'
import RepositoryClaudeAccountController from './RepositoryClaudeAccountController'
import RepositoryActionController from './RepositoryActionController'
import ManifestController from './ManifestController'

const Repositories = {
    RepositoryController: Object.assign(RepositoryController, RepositoryController),
    GitHubRepoSearchController: Object.assign(GitHubRepoSearchController, GitHubRepoSearchController),
    GitHubCiDetectController: Object.assign(GitHubCiDetectController, GitHubCiDetectController),
    RiskProfileController: Object.assign(RiskProfileController, RiskProfileController),
    RepositoryClaudeAccountController: Object.assign(RepositoryClaudeAccountController, RepositoryClaudeAccountController),
    RepositoryActionController: Object.assign(RepositoryActionController, RepositoryActionController),
    ManifestController: Object.assign(ManifestController, ManifestController),
}

export default Repositories