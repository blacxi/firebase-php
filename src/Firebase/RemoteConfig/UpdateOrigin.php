<?php

declare(strict_types=1);

namespace Kreait\Firebase\RemoteConfig;

use JsonSerializable;
use Kreait\Firebase\Value;

final class UpdateOrigin implements Value, JsonSerializable
{
    public const UNSPECIFIED = 'REMOTE_CONFIG_UPDATE_ORIGIN_UNSPECIFIED';
    public const CONSOLE = 'CONSOLE';
    public const REST_API = 'REST_API';

    /**
     * @var string
     */
    private $value;

    private function __construct()
    {
    }

    public static function fromValue($value): self
    {
        $new = new self();
        $new->value = (string) $value;

        return $new;
    }

    public function __toString()
    {
        return $this->value;
    }

    public function jsonSerialize()
    {
        return $this->value;
    }

    public function equalsTo($other): bool
    {
        return $this->value === (string) $other;
    }
}
