import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ProfileController::resend
* @see app/Http/Controllers/Settings/ProfileController.php:45
* @route '/settings/profile/resend-verification'
*/
export const resend = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(options),
    method: 'post',
})

resend.definition = {
    methods: ["post"],
    url: '/settings/profile/resend-verification',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\ProfileController::resend
* @see app/Http/Controllers/Settings/ProfileController.php:45
* @route '/settings/profile/resend-verification'
*/
resend.url = (options?: RouteQueryOptions) => {
    return resend.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProfileController::resend
* @see app/Http/Controllers/Settings/ProfileController.php:45
* @route '/settings/profile/resend-verification'
*/
resend.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resend.url(options),
    method: 'post',
})

const verification = {
    resend: Object.assign(resend, resend),
}

export default verification