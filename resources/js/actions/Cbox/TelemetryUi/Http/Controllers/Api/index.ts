import BootstrapController from './BootstrapController'
import ViewStateController from './ViewStateController'
import PageController from './PageController'
import PanelController from './PanelController'
import ExploreController from './ExploreController'
import FacetsController from './FacetsController'
import EntityController from './EntityController'
import TraceController from './TraceController'
import ErrorGroupController from './ErrorGroupController'
import IssueController from './IssueController'
import AnnotationsController from './AnnotationsController'
import MonitorsController from './MonitorsController'
import DimensionLabelsController from './DimensionLabelsController'
import StreamController from './StreamController'

const Api = {
    BootstrapController: Object.assign(BootstrapController, BootstrapController),
    ViewStateController: Object.assign(ViewStateController, ViewStateController),
    PageController: Object.assign(PageController, PageController),
    PanelController: Object.assign(PanelController, PanelController),
    ExploreController: Object.assign(ExploreController, ExploreController),
    FacetsController: Object.assign(FacetsController, FacetsController),
    EntityController: Object.assign(EntityController, EntityController),
    TraceController: Object.assign(TraceController, TraceController),
    ErrorGroupController: Object.assign(ErrorGroupController, ErrorGroupController),
    IssueController: Object.assign(IssueController, IssueController),
    AnnotationsController: Object.assign(AnnotationsController, AnnotationsController),
    MonitorsController: Object.assign(MonitorsController, MonitorsController),
    DimensionLabelsController: Object.assign(DimensionLabelsController, DimensionLabelsController),
    StreamController: Object.assign(StreamController, StreamController),
}

export default Api