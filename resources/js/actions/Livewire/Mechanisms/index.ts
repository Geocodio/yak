import HandleRequests from './HandleRequests'
import FrontendAssets from './FrontendAssets'
import HandleRouting from './HandleRouting'

const Mechanisms = {
    HandleRequests: Object.assign(HandleRequests, HandleRequests),
    FrontendAssets: Object.assign(FrontendAssets, FrontendAssets),
    HandleRouting: Object.assign(HandleRouting, HandleRouting),
}

export default Mechanisms