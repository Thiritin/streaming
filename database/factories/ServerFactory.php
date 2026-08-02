<?php

namespace Database\Factories;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    /**
     * Default is a manually managed edge server: no hetzner_id, which is the
     * distinction the panel keys "Delete" versus "Deprovision" off.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hetzner_id' => null,
            'hostname' => $this->faker->unique()->domainWord().'.edge.test',
            'ip' => $this->faker->ipv4(),
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'shared_secret' => Str::random(40),
            'max_clients' => 100,
            'viewer_count' => 0,
            'immutable' => true,
        ];
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
     * A Hetzner-managed server, which is what makes "Deprovision" the offered action.
     */
    public function cloud(): self
    {
        return $this->state(['hetzner_id' => (string) $this->faker->unique()->numberBetween(1000000, 9999999)]);
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
