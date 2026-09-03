import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ProfileController::edit
* @see app/Http/Controllers/Settings/ProfileController.php:17
* @route '/settings/profile'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/profile',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProfileController::edit
* @see app/Http/Controllers/Settings/ProfileController.php:17
* @route '/settings/profile'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProfileController::edit
* @see app/Http/Controllers/Settings/ProfileController.php:17
* @route '/settings/profile'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProfileController::edit
* @see app/Http/Controllers/Settings/ProfileController.php:17
* @route '/settings/profile'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProfileController::update
* @see app/Http/Controllers/Settings/ProfileController.php:30
* @route '/settings/profile'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/profile',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\ProfileController::update
* @see app/Http/Controllers/Settings/ProfileController.php:30
* @route '/settings/profile'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProfileController::update
* @see app/Http/Controllers/Settings/ProfileController.php:30
* @route '/settings/profile'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

const profile = {
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
}

export default profile