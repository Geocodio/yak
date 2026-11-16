import App from './App'
import Cli from './Cli'

const Api = {
    App: Object.assign(App, App),
    Cli: Object.assign(Cli, Cli),
}

export default Api