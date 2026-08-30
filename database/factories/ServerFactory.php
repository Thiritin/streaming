<?php

namespace Database\Factories;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Support\CloudSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    /**
     * Default is a manually managed edge server, which is the distinction the panel
     * keys "Delete" versus "Deprovision" off.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => CloudSettings::MANUAL,
            'external_id' => null,
            'hetzner_id' => null,
            'hostname' => $this->faker->unique()->domainWord().'.edge.test',
            'ip' => $this->faker->ipv4(),
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
            'viewer_count' => 0,
            'immutable' => true,
        ];
    }

    /**
     * A server whose shared secret is a known string, so a test can present it.
     *
     * Only the hash is stored, and the model mints one on create unless a hash is
     * already set, so seeding the hash is how a fixture pins the plaintext.
     */
    public function credential(string $plaintext): self
    {
        return $this->state(['shared_secret_hash' => Server::hashCredential($plaintext)]);
    }

    public function edge(): self
    {
        return $this->state(['type' => ServerTypeEnum::EDGE]);
    }

    public function origin(): self
    {
        return $this->state([
            'type' => ServerTypeEnum::ORIGIN,
            'hostname' => 'origin.'.$this->faker->unique()->domainWord().'.test',
            'port' => 443,
            'max_clients' => 1000,
        ]);
    }

    /**
     * A provider-managed server, which is what makes "Deprovision" the offered action.
     */
    public function cloud(): self
    {
        $id = (string) $this->faker->unique()->numberBetween(1000000, 9999999);

        return $this->state([
            'provider' => CloudSettings::HETZNER,
            'external_id' => $id,
            // Written in parallel for one release; edges in the field still POST it.
            'hetzner_id' => $id,
        ]);
    }

    public function status(ServerStatusEnum $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function withHeartbeat(): self
    {
        return $this->state(['last_heartbeat' => now()]);
    }

    public function healthy(): self
    {
        return $this->state([
            'health_status' => 'healthy',
            'last_health_check' => now(),
            'health_check_message' => 'Health check passed',
        ]);
    }
}
