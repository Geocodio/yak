import ApiIndexController from './ApiIndexController'
import HealthCheckController from './HealthCheckController'
import OverviewController from './OverviewController'
import AllJobsController from './AllJobsController'
import AllQueuesController from './AllQueuesController'
import AllServersController from './AllServersController'
import AllWorkersController from './AllWorkersController'
import JobMetricsController from './JobMetricsController'
import QueueMetricsController from './QueueMetricsController'
import WorkerController from './WorkerController'
import WorkerStatusController from './WorkerStatusController'
import QueueDepthController from './QueueDepthController'
import ServerMetricsController from './ServerMetricsController'
import PrometheusController from './PrometheusController'

const Controllers = {
    ApiIndexController: Object.assign(ApiIndexController, ApiIndexController),
    HealthCheckController: Object.assign(HealthCheckController, HealthCheckController),
    OverviewController: Object.assign(OverviewController, OverviewController),
    AllJobsController: Object.assign(AllJobsController, AllJobsController),
    AllQueuesController: Object.assign(AllQueuesController, AllQueuesController),
    AllServersController: Object.assign(AllServersController, AllServersController),
    AllWorkersController: Object.assign(AllWorkersController, AllWorkersController),
    JobMetricsController: Object.assign(JobMetricsController, JobMetricsController),
    QueueMetricsController: Object.assign(QueueMetricsController, QueueMetricsController),
    WorkerController: Object.assign(WorkerController, WorkerController),
    WorkerStatusController: Object.assign(WorkerStatusController, WorkerStatusController),
    QueueDepthController: Object.assign(QueueDepthController, QueueDepthController),
    ServerMetricsController: Object.assign(ServerMetricsController, ServerMetricsController),
    PrometheusController: Object.assign(PrometheusController, PrometheusController),
}

export default Controllers