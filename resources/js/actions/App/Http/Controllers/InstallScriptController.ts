import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\InstallScriptController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/InstallScriptController.php:13
* @route '/install'
*/
const InstallScriptController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: InstallScriptController.url(options),
    method: 'get',
})

InstallScriptController.definition = {
    methods: ["get","head"],
    url: '/install',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\InstallScriptController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/InstallScriptController.php:13
* @route '/install'
*/
InstallScriptController.url = (options?: RouteQueryOptions) => {
    return InstallScriptController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\InstallScriptController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/InstallScriptController.php:13
* @route '/install'
*/
InstallScriptController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: InstallScriptController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\InstallScriptController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/InstallScriptController.php:13
* @route '/install'
*/
InstallScriptController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: InstallScriptController.url(options),
    method: 'head',
})

export default InstallScriptController