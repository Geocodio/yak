import RepositoryController from './RepositoryController'
import GitHubRepoSearchController from './GitHubRepoSearchController'
import GitHubCiDetectController from './GitHubCiDetectController'
import RepositoryActionController from './RepositoryActionController'
import ManifestController from './ManifestController'

const Repositories = {
    RepositoryController: Object.assign(RepositoryController, RepositoryController),
    GitHubRepoSearchController: Object.assign(GitHubRepoSearchController, GitHubRepoSearchController),
    GitHubCiDetectController: Object.assign(GitHubCiDetectController, GitHubCiDetectController),
    RepositoryActionController: Object.assign(RepositoryActionController, RepositoryActionController),
    ManifestController: Object.assign(ManifestController, ManifestController),
}

export default Repositories