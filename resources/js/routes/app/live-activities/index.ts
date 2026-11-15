import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityController::update
* @see app/Http/Controllers/Api/App/Push/LiveActivityController.php:22
* @route '/api/app/v1/live-activities'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/app/v1/live-activities',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityController::update
* @see app/Http/Controllers/Api/App/Push/LiveActivityController.php:22
* @route '/api/app/v1/live-activities'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\LiveActivityController::update
* @see app/Http/Controllers/Api/App/Push/LiveActivityController.php:22
* @route '/api/app/v1/live-activities'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

const liveActivities = {
    update: Object.assign(update, update),
}

export default liveActivities