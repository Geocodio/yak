import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
const SshTerminalCheckController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshTerminalCheckController.url(options),
    method: 'post',
})

SshTerminalCheckController.definition = {
    methods: ["post"],
    url: '/internal/ssh/terminal/check',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
SshTerminalCheckController.url = (options?: RouteQueryOptions) => {
    return SshTerminalCheckController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
SshTerminalCheckController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshTerminalCheckController.url(options),
    method: 'post',
})

export default SshTerminalCheckController