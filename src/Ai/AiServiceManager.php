<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use Grafida\Secret\SecretStore;
use Grafida\Site\SecureStoreUnavailableException;

/**
 * Application service that orchestrates AI service persistence and API-key storage.
 *
 * API keys are stored in the OS keychain when one is available (reference
 * `grafida.ai_service.{id}`), falling back to plaintext in the database only
 * when no secure store is present and the caller explicitly opts in.
 */
final class AiServiceManager
{
    public function __construct(
        private readonly AiServiceRepository $repository,
        private readonly ?SecretStore $secureStore,
    ) {}

    /** @return list<AiService> */
    public function list(): array
    {
        return $this->repository->all();
    }

    public function find(int $id): ?AiService
    {
        return $this->repository->find($id);
    }

    public function hasSecureStore(): bool
    {
        return $this->secureStore !== null;
    }

    /**
     * Resolves the API key for an AI service from wherever it is stored.
     */
    public function resolveKey(int $id): ?string
    {
        $service = $this->repository->find($id);

        if ($service === null) {
            return null;
        }

        if ($service->secretRef !== null && $this->secureStore !== null) {
            return $this->secureStore->get($service->secretRef);
        }

        return $service->insecureKey;
    }

    /**
     * Returns the effective default AI service.
     *
     * Prefers the service whose `is_default` flag is set; when none is marked
     * the service with the lowest id is returned (consistent with the UI
     * pre-selecting the oldest entry). Returns null when no services exist.
     */
    public function default(): ?AiService
    {
        $services = $this->repository->all();

        if ($services === []) {
            return null;
        }

        foreach ($services as $service) {
            if ($service->isDefault) {
                return $service;
            }
        }

        // all() returns ORDER BY id ASC — first element has the lowest id.
        return $services[0];
    }

    /**
     * Creates a new AI service, storing its key in the most secure place available.
     *
     * @param array{name: string, provider: string, endpoint: string, model: string,
     *              key?: string, params?: array<string, mixed>, allowInsecure?: bool} $data
     *
     * @throws SecureStoreUnavailableException When a non-empty key is supplied, no OS store
     *                                         is available and allowInsecure is false.
     */
    public function create(array $data): AiService
    {
        $key           = isset($data['key']) && is_string($data['key']) ? $data['key'] : '';
        $allowInsecure = isset($data['allowInsecure']) && (bool) $data['allowInsecure'];

        /** @var array<string, mixed> $params */
        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];

        // Insert with no key fields first to obtain the auto-generated id.
        $stub = new AiService(
            id: null,
            name: $data['name'],
            provider: $data['provider'],
            endpoint: $data['endpoint'],
            model: $data['model'],
            params: $params,
            secretRef: null,
            insecureKey: null,
            isDefault: false,
        );

        $id = $this->repository->insert($stub);

        // Now that we have the id, store the key under the id-based reference.
        [$secretRef, $insecureKey] = $key !== ''
            ? $this->persistKey($id, $key, $allowInsecure)
            : [null, null];

        $service = new AiService(
            id: $id,
            name: $stub->name,
            provider: $stub->provider,
            endpoint: $stub->endpoint,
            model: $stub->model,
            params: $stub->params,
            secretRef: $secretRef,
            insecureKey: $insecureKey,
            isDefault: false,
        );

        $this->repository->update($service);

        $result = $this->repository->find($id);
        \assert($result !== null);

        return $result;
    }

    /**
     * Updates an existing AI service.
     *
     * The key is only re-stored when a non-empty `key` value is provided in
     * $data; omitting the key or supplying an empty string leaves the stored
     * credential untouched.
     *
     * @param array{name?: string, provider?: string, endpoint?: string, model?: string,
     *              key?: string, params?: array<string, mixed>, allowInsecure?: bool} $data
     *
     * @throws \InvalidArgumentException       When the service does not exist.
     * @throws SecureStoreUnavailableException When a new non-empty key is supplied, no OS store
     *                                         is available and allowInsecure is false.
     */
    public function update(int $id, array $data): AiService
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new \InvalidArgumentException('Unknown AI service #' . $id);
        }

        $key           = isset($data['key']) && is_string($data['key']) ? $data['key'] : '';
        $allowInsecure = isset($data['allowInsecure']) && (bool) $data['allowInsecure'];

        /** @var array<string, mixed> $params */
        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : $existing->params;

        $secretRef   = $existing->secretRef;
        $insecureKey = $existing->insecureKey;

        if ($key !== '') {
            // Purge the old secret before writing the new one.
            if ($existing->secretRef !== null && $this->secureStore !== null) {
                $this->secureStore->delete($existing->secretRef);
            }

            [$secretRef, $insecureKey] = $this->persistKey($id, $key, $allowInsecure);
        }

        $service = new AiService(
            id: $id,
            name: isset($data['name']) && is_string($data['name']) ? $data['name'] : $existing->name,
            provider: isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : $existing->provider,
            endpoint: isset($data['endpoint']) && is_string($data['endpoint']) ? $data['endpoint'] : $existing->endpoint,
            model: isset($data['model']) && is_string($data['model']) ? $data['model'] : $existing->model,
            params: $params,
            secretRef: $secretRef,
            insecureKey: $insecureKey,
            isDefault: $existing->isDefault,
        );

        $this->repository->update($service);

        $result = $this->repository->find($id);
        \assert($result !== null);

        return $result;
    }

    /**
     * Deletes an AI service and removes its stored API key from the OS keychain.
     */
    public function delete(int $id): void
    {
        $service = $this->repository->find($id);

        if ($service?->secretRef !== null && $this->secureStore !== null) {
            $this->secureStore->delete($service->secretRef);
        }

        $this->repository->delete($id);
    }

    /**
     * Marks one service as the sole default (clears all others first).
     *
     * @throws \InvalidArgumentException When the service does not exist.
     */
    public function setDefault(int $id): void
    {
        if ($this->repository->find($id) === null) {
            throw new \InvalidArgumentException('Unknown AI service #' . $id);
        }

        $this->repository->setDefault($id);
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * Decides where to store the key and returns [secretRef, insecureKey].
     *
     * @return array{0: ?string, 1: ?string}
     *
     * @throws SecureStoreUnavailableException
     */
    private function persistKey(int $serviceId, string $key, bool $allowInsecure): array
    {
        if ($this->secureStore !== null) {
            $ref = 'grafida.ai_service.' . $serviceId;
            $this->secureStore->set($ref, $key);

            return [$ref, null];
        }

        if (!$allowInsecure) {
            throw new SecureStoreUnavailableException(
                'No OS secret store is available on this platform.'
            );
        }

        return [null, $key];
    }
}
