<?php

use App\Actions\Database\StartValkey;
use App\Livewire\Project\Database\Valkey\General;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneValkey;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Process::fake();
    Queue::fake();
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'valkey-test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

test('create_standalone_valkey creates a valid StandaloneValkey model with defaults', function () {
    $valkey = create_standalone_valkey($this->environment->id, $this->destination);

    expect($valkey)->toBeInstanceOf(StandaloneValkey::class)
        ->and($valkey->exists)->toBeTrue()
        ->and($valkey->name)->toStartWith('valkey-database-')
        ->and($valkey->image)->toBe('valkey/valkey:9.0-alpine')
        ->and($valkey->valkey_password)->not->toBeEmpty()
        ->and($valkey->type())->toBe('standalone-valkey')
        ->and($valkey->database_type)->toBe('standalone-valkey')
        ->and($valkey->internal_db_url)->toBe("redis://:{$valkey->valkey_password}@{$valkey->uuid}:6379/0");
});

test('StandaloneValkey generates correct internal and external URLs with SSL', function () {
    $valkey = create_standalone_valkey($this->environment->id, $this->destination, [
        'enable_ssl' => true,
        'is_public' => true,
        'public_port' => 26379,
    ]);

    expect($valkey->internal_db_url)->toBe("rediss://:{$valkey->valkey_password}@{$valkey->uuid}:6380/0")
        ->and($valkey->external_db_url)->toContain("rediss://:{$valkey->valkey_password}@")
        ->and($valkey->external_db_url)->toContain(':26379/0');
});

test('StartValkey generates docker compose with correct image, healthcheck, and command', function () {
    $valkey = create_standalone_valkey($this->environment->id, $this->destination);

    StartValkey::run($valkey, new Activity);

    $composeCommand = collect($this->executor->commands)->first(
        fn (string $cmd) => str_contains($cmd, 'docker-compose.yml') && str_contains($cmd, 'base64 -d')
    );

    expect($composeCommand)->not->toBeNull();

    preg_match("#^echo '([^']+)' \| base64 -d#", $composeCommand, $matches);
    $composeYaml = base64_decode($matches[1]);
    $parsed = Yaml::parse($composeYaml);

    $service = $parsed['services'][$valkey->uuid];

    expect($service['image'])->toBe('valkey/valkey:9.0-alpine')
        ->and($service['container_name'])->toBe($valkey->uuid)
        ->and($service['healthcheck']['test'])->toContain('valkey-cli')
        ->and($service['healthcheck']['test'])->toContain('ping')
        ->and($service['command'])->toContain('valkey-server')
        ->and($service['command'])->toContain('--requirepass')
        ->and($service['command'])->toContain($valkey->valkey_password);
});

test('API can create, retrieve, update, and delete a Valkey database', function () {
    // 1. Create Valkey via API
    $createResponse = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->postJson('/api/v1/databases/valkey', [
            'project_uuid' => $this->project->uuid,
            'environment_name' => $this->environment->name,
            'server_uuid' => $this->server->uuid,
            'name' => 'api-created-valkey',
            'description' => 'Test Valkey instance',
            'valkey_password' => 'supersecretvalkey123',
            'instant_deploy' => false,
        ])
        ->assertCreated();

    $valkeyUuid = $createResponse->json('uuid');
    expect($valkeyUuid)->not->toBeNull();

    // 2. Retrieve by UUID
    $getResponse = $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->getJson("/api/v1/databases/{$valkeyUuid}")
        ->assertOk();

    expect($getResponse->json('name'))->toBe('api-created-valkey')
        ->and($getResponse->json('database_type'))->toBe('standalone-valkey');

    // 3. Update by UUID
    $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->patchJson("/api/v1/databases/{$valkeyUuid}", [
            'name' => 'api-updated-valkey',
            'description' => 'Updated Valkey instance',
            'valkey_password' => 'updatedpassword123',
        ])
        ->assertOk();

    $valkeyModel = StandaloneValkey::where('uuid', $valkeyUuid)->firstOrFail();
    expect($valkeyModel->name)->toBe('api-updated-valkey')
        ->and($valkeyModel->valkey_password)->toBe('updatedpassword123');

    // 4. Delete by UUID
    $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->deleteJson("/api/v1/databases/{$valkeyUuid}")
        ->assertOk();

    expect(StandaloneValkey::where('uuid', $valkeyUuid)->first())->toBeNull()
        ->and(StandaloneValkey::withTrashed()->where('uuid', $valkeyUuid)->first())->not->toBeNull();
});

test('Livewire General component can mount and save Valkey settings', function () {
    $this->actingAs($this->user);
    $valkey = create_standalone_valkey($this->environment->id, $this->destination);

    Livewire::test(General::class, ['database' => $valkey])
        ->assertSet('name', $valkey->name)
        ->set('name', 'livewire-renamed-valkey')
        ->call('submit')
        ->assertHasNoErrors();

    expect($valkey->fresh()->name)->toBe('livewire-renamed-valkey');
});
