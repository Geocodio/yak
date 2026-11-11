<?php

namespace Tests\Support;

use App\Models\PushDevice;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for a browser push service: VAPID keys, real subscription keys
 * so payload encryption succeeds, and a recorded request history.
 */
final class FakePushEndpoint
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private MockHandler $mock;

    private function __construct()
    {
        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $keys = VAPID::createVapidKeys();
        config([
            'webpush.vapid.subject' => 'mailto:yak@example.test',
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
            'webpush.client_options' => ['handler' => $stack],
        ]);
    }

    public static function install(): self
    {
        return new self;
    }

    /**
     * Queue the next response; defaults to 201 Created, which push services return on success.
     */
    public function respondWith(int $status = 201): self
    {
        $this->mock->append(new Response($status));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function subscription(User $user, array $overrides = []): PushDevice
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);
        $publicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        return PushDevice::factory()->forUser($user)->create(array_merge([
            'public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ], $overrides));
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_map(fn (array $entry): RequestInterface => $entry['request'], $this->history);
    }

    public function assertSentTo(string $endpoint): void
    {
        $matching = array_filter($this->requests(), fn (RequestInterface $request): bool => (string) $request->getUri() === $endpoint);

        Assert::assertNotEmpty($matching, "No push request reached {$endpoint}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->requests(), 'Expected no push requests.');
    }
}
