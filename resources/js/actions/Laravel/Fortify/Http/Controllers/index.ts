import AuthenticatedSessionController from './AuthenticatedSessionController'
import ConfirmedPasswordStatusController from './ConfirmedPasswordStatusController'
import ConfirmablePasswordController from './ConfirmablePasswordController'

const Controllers = {
    AuthenticatedSessionController: Object.assign(AuthenticatedSessionController, AuthenticatedSessionController),
    ConfirmedPasswordStatusController: Object.assign(ConfirmedPasswordStatusController, ConfirmedPasswordStatusController),
    ConfirmablePasswordController: Object.assign(ConfirmablePasswordController, ConfirmablePasswordController),
}

export default Controllers