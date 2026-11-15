<?php

namespace App\Services\Push\Apns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every call to Apple. Each call has hard timeouts and a failure to reach Apple, or to build the request, becomes an {@see ApnsResponse} instead of an exception.
 *
 * Push that is not configured is the one exception: the public methods throw a RuntimeException, so callers check {@see ApnsConfig::isConfigured()} first.
 *
 * Nothing here logs, reports or rethrows a transport exception, because its message holds the request URL and the device token with it.
 */
final class ApnsClient
{
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly ApnsConfig $config, private readonly ApnsProviderToken $providerToken) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendAlert(#[\SensitiveParameter] string $deviceToken, array $payload, string $collapseId): ApnsResponse
    {
        return $this->call('POST', $this->deviceUrl($deviceToken), [
            'apns-push-type' => 'alert',
            'apns-topic' => $this->bundleId(),
            'apns-priority' => '10',
            'apns-expiration' => (string) now()->addDay()->getTimestamp(),
            'apns-collapse-id' => $collapseId,
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendLiveActivityStart(#[\SensitiveParameter] string $deviceToken, array $payload): ApnsResponse
    {
        return $this->call('POST', $this->deviceUrl($deviceToken), [
            'apns-push-type' => 'liveactivity',
            'apns-topic' => $this->bundleId() . '.push-type.liveactivity',
            'apns-priority' => '10',
        ], $payload);
    }

    /**
     * A WidgetKit push: push type `widgets`, topic `<bundle id>.push-type.widgets`, low priority (5) so the system may defer it, and a body of `{"aps": {"content-changed": true}}`.
     * The collapse id lets Apple keep only the newest reload for a device.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendWidgetReload(#[\SensitiveParameter] string $deviceToken, array $payload): ApnsResponse
    {
        return $this->call('POST', $this->deviceUrl($deviceToken), [
            'apns-push-type' => 'widgets',
            'apns-topic' => $this->bundleId() . '.push-type.widgets',
            'apns-priority' => '5',
            'apns-expiration' => (string) now()->addHour()->getTimestamp(),
            'apns-collapse-id' => 'widget-reload',
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(string $channelId, array $payload, int $priority): ApnsResponse
    {
        $url = ApnsHosts::broadcast($this->config->environment()) . '/4/broadcasts/apps/' . $this->bundleId();

        return $this->call('POST', $url, [
            'apns-channel-id' => $channelId,
            'apns-push-type' => 'liveactivity',
            'apns-priority' => (string) $priority,
        ], $payload);
    }

    /**
     * With `$repeatAfterRenewal` false a provider token problem renews the token and returns Apple's answer without a second call, so a request path stays inside its time budget.
     */
    public function createChannel(bool $repeatAfterRenewal = true): ApnsResponse
    {
        return $this->call('POST', $this->channelsUrl(), [], [
            'message-storage-policy' => ApnsHosts::MESSAGE_STORAGE_MOST_RECENT,
            'push-type' => 'LiveActivity',
        ], $repeatAfterRenewal);
    }

    /**
     * A channel Apple no longer knows is a deleted channel.
     */
    public function deleteChannel(string $channelId): ApnsResponse
    {
        $response = $this->call('DELETE', $this->channelsUrl(), ['apns-channel-id' => $channelId], null);

        return $response->status === 404 ? new ApnsResponse(204) : $response;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     */
    private function call(string $method, #[\SensitiveParameter] string $url, array $headers, ?array $body, bool $repeatAfterRenewal = true): ApnsResponse
    {
        $token = $this->providerToken->current();
        $response = $this->attempt($method, $url, $headers, $body, $token);

        if (! $response->isProviderTokenProblem()) {
            return $response;
        }

        $renewed = $this->providerToken->renew($token);

        return $repeatAfterRenewal ? $this->attempt($method, $url, $headers, $body, $renewed) : $response;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     */
    private function attempt(string $method, #[\SensitiveParameter] string $url, array $headers, ?array $body, #[\SensitiveParameter] string $providerToken): ApnsResponse
    {
        try {
            $request = Http::withOptions(['version' => 2.0])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withToken($providerToken)
                ->withHeaders($headers)
                ->asJson();

            return $this->answer($this->dispatch($request, $method, $url, $body));
        } catch (\Throwable $exception) {
            return ApnsResponse::timeout($exception::class);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function dispatch(PendingRequest $request, string $method, #[\SensitiveParameter] string $url, ?array $body): Response
    {
        return $method === 'DELETE' ? $request->delete($url) : $request->post($url, $body ?? []);
    }

    private function answer(Response $response): ApnsResponse
    {
        $reason = $response->json('reason');

        return new ApnsResponse(
            $response->status(),
            is_string($reason) ? $reason : null,
            $response->header('apns-channel-id') ?: null,
        );
    }

    private function deviceUrl(#[\SensitiveParameter] string $deviceToken): string
    {
        return ApnsHosts::device($this->config->environment()) . '/3/device/' . $deviceToken;
    }

    private function channelsUrl(): string
    {
        return ApnsHosts::channels($this->config->environment()) . '/1/apps/' . $this->bundleId() . '/channels';
    }

    private function bundleId(): string
    {
        return $this->config->bundleId() ?? '';
    }
}
