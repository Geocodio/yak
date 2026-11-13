<?php

namespace App\Models\Concerns;

use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Gives a model an eight character public_id that URLs and route parameters use
 * instead of the integer primary key. A numeric URL segment is a legacy link: a
 * GET redirects to the public id when the viewer may see the row (the global
 * scopes decide), the CLI API still accepts it, and every other request misses.
 *
 * @mixin Model
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', static::generateUniquePublicId());
            }
        });
    }

    public static function generateUniquePublicId(): string
    {
        do {
            $publicId = PublicId::generate();
        } while (static::query()->withoutGlobalScopes()->where('public_id', $publicId)->exists());

        return $publicId;
    }

    /**
     * The public id of the row with this primary key, whoever may see it. Used to build links from a foreign key.
     */
    public static function publicIdForKey(int $key): ?string
    {
        $publicId = static::query()->withoutGlobalScopes()->whereKey($key)->first(['public_id'])?->getAttribute('public_id');

        return is_string($publicId) ? $publicId : null;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null || ! ctype_digit((string) $value)) {
            return parent::resolveRouteBinding($value, $field);
        }

        $request = request();

        if ($request->is('api/cli/*')) {
            return parent::resolveRouteBinding($value, $this->getKeyName());
        }

        if (! $request->isMethod('GET')) {
            return null;
        }

        $model = parent::resolveRouteBinding($value, $this->getKeyName());

        if ($model !== null) {
            throw new HttpResponseException(redirect()->to($this->publicIdUrl($request, (string) $value, $model), 301));
        }

        return null;
    }

    private function publicIdUrl(Request $request, string $legacyValue, Model $model): string
    {
        $route = $request->route() ?? abort(404);
        $parameterName = collect($route->parameters())->search(fn ($parameter) => (string) $parameter === $legacyValue);
        $segments = explode('/', $request->path());
        $templateSegments = explode('/', $route->uri());
        $index = array_search('{' . $parameterName . '}', $templateSegments, true);

        if ($index !== false) {
            $segments[$index] = $model->getRouteKey();
        }

        $query = $request->getQueryString();

        return $request->getSchemeAndHttpHost() . '/' . implode('/', $segments) . ($query === null ? '' : '?' . $query);
    }
}
