import SessionController from './SessionController'
import PrReviewController from './PrReviewController'

const AppEmbed = {
    SessionController: Object.assign(SessionController, SessionController),
    PrReviewController: Object.assign(PrReviewController, PrReviewController),
}

export default AppEmbed