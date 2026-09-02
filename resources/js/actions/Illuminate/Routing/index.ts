import ViewController from './ViewController'
import RedirectController from './RedirectController'

const Routing = {
    ViewController: Object.assign(ViewController, ViewController),
    RedirectController: Object.assign(RedirectController, RedirectController),
}

export default Routing