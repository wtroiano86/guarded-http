<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines\Profiles;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;

/**
 * Selects a profile definition before its settings are evaluated.
 *
 * Reads the current configuration for string inputs and resolves profile
 * classes through the container. Existing profile instances pass through.
 */
final readonly class ResolveProfileDefinition
{
    public function __construct(private Repository $config, private Container $container) {}

    /**
     * Resolve an exact configured name or a RequestProfile class name.
     *
     * Configuration keys take precedence over class names. Named definitions
     * may be settings arrays or class names; aliases to other names are not
     * followed. Container resolution failures propagate unchanged.
     *
     * @return array<array-key, mixed>|RequestProfile
     *
     * @throws InvalidArgumentException When configuration or a profile is invalid.
     */
    public function __invoke(string|RequestProfile $profile): array|RequestProfile
    {
        if ($profile instanceof RequestProfile) {
            return $profile;
        }

        $profiles = $this->config->get('guarded-http.profiles', []);
        throw_unless(is_array($profiles), InvalidArgumentException::class, 'Guarded HTTP profiles must be an array.');

        $definition = array_key_exists($profile, $profiles) ? $profiles[$profile] : $profile;
        if (is_string($definition)) {
            throw_unless(is_a($definition, RequestProfile::class, true), InvalidArgumentException::class, 'Unknown Guarded HTTP profile or invalid profile class: '.$definition);
            $resolved = $this->container->make($definition);
            throw_unless($resolved instanceof RequestProfile, InvalidArgumentException::class, 'The resolved HTTP profile must implement RequestProfile.');

            return $resolved;
        }

        throw_unless(is_array($definition), InvalidArgumentException::class, 'An HTTP profile must be a settings array or a RequestProfile class.');

        return $definition;
    }
}
