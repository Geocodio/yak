import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\PeopleController::update
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/PeopleController.php:30
* @route '/settings/people/{user}'
*/
export const update = (args: { user: string | number | { id: string | number } } | [user: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/people/{user}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\PeopleController::update
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/PeopleController.php:30
* @route '/settings/people/{user}'
*/
update.url = (args: { user: string | number | { id: string | number } } | [user: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { user: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { user: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            user: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        user: typeof args.user === 'object'
        ? args.user.id
        : args.user,
    }

    return update.definition.url
            .replace('{user}', parsedArgs.user.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PeopleController::update
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/PeopleController.php:30
* @route '/settings/people/{user}'
*/
update.patch = (args: { user: string | number | { id: string | number } } | [user: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

const people = {
    update: Object.assign(update, update),
}

export default people