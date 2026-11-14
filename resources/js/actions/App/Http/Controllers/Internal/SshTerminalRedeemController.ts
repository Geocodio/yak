import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
const SshTerminalRedeemController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshTerminalRedeemController.url(options),
    method: 'post',
})

SshTerminalRedeemController.definition = {
    methods: ["post"],
    url: '/internal/ssh/terminal/redeem',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
SshTerminalRedeemController.url = (options?: RouteQueryOptions) => {
    return SshTerminalRedeemController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
SshTerminalRedeemController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshTerminalRedeemController.url(options),
    method: 'post',
})

export default SshTerminalRedeemController