import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::begin
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:23
* @route '/api/cli/v1/teleports'
*/
export const begin = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: begin.url(options),
    method: 'post',
})

begin.definition = {
    methods: ["post"],
    url: '/api/cli/v1/teleports',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::begin
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:23
* @route '/api/cli/v1/teleports'
*/
begin.url = (options?: RouteQueryOptions) => {
    return begin.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::begin
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:23
* @route '/api/cli/v1/teleports'
*/
begin.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: begin.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:49
* @route '/api/cli/v1/teleports/{teleport}'
*/
export const show = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/teleports/{teleport}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:49
* @route '/api/cli/v1/teleports/{teleport}'
*/
show.url = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { teleport: args }
    }

    if (Array.isArray(args)) {
        args = {
            teleport: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        teleport: args.teleport,
    }

    return show.definition.url
            .replace('{teleport}', parsedArgs.teleport.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:49
* @route '/api/cli/v1/teleports/{teleport}'
*/
show.get = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:49
* @route '/api/cli/v1/teleports/{teleport}'
*/
show.head = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::complete
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:74
* @route '/api/cli/v1/teleports/{teleport}/complete'
*/
export const complete = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: complete.url(args, options),
    method: 'post',
})

complete.definition = {
    methods: ["post"],
    url: '/api/cli/v1/teleports/{teleport}/complete',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::complete
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:74
* @route '/api/cli/v1/teleports/{teleport}/complete'
*/
complete.url = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { teleport: args }
    }

    if (Array.isArray(args)) {
        args = {
            teleport: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        teleport: args.teleport,
    }

    return complete.definition.url
            .replace('{teleport}', parsedArgs.teleport.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::complete
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:74
* @route '/api/cli/v1/teleports/{teleport}/complete'
*/
complete.post = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: complete.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::cancel
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:61
* @route '/api/cli/v1/teleports/{teleport}/cancel'
*/
export const cancel = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/api/cli/v1/teleports/{teleport}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::cancel
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:61
* @route '/api/cli/v1/teleports/{teleport}/cancel'
*/
cancel.url = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { teleport: args }
    }

    if (Array.isArray(args)) {
        args = {
            teleport: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        teleport: args.teleport,
    }

    return cancel.definition.url
            .replace('{teleport}', parsedArgs.teleport.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\TeleportController::cancel
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/TeleportController.php:61
* @route '/api/cli/v1/teleports/{teleport}/cancel'
*/
cancel.post = (args: { teleport: string | number } | [teleport: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

const TeleportController = { begin, show, complete, cancel }

export default TeleportController