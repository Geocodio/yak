import GoogleAuthController from './GoogleAuthController'
import LinearOAuthController from './LinearOAuthController'

const Auth = {
    GoogleAuthController: Object.assign(GoogleAuthController, GoogleAuthController),
    LinearOAuthController: Object.assign(LinearOAuthController, LinearOAuthController),
}

export default Auth